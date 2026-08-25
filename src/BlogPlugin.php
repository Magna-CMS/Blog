<?php

declare(strict_types=1);

namespace MagnaCms\Blog;

use Filament\Support\Facades\FilamentAsset;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Translation\Translator;
use Magna\Blocks\BlockDefinition;
use Magna\Blocks\DataSources\DataSource;
use Magna\Blocks\Resolution\BlockDataResolver;
use Magna\Contracts\HandlesPersonalData;
use Magna\Contracts\RegistersAdminResources;
use Magna\Contracts\RegistersBlocks;
use Magna\Contracts\RegistersCommands;
use Magna\Contracts\RegistersDataSources;
use Magna\Contracts\RegistersSettingsPages;
use Magna\Contracts\RegistersWebhookEvents;
use Magna\Plugins\Plugin;
use MagnaCms\Blog\Blocks\BlogPostsBlockResolver;
use MagnaCms\Blog\Blocks\DataSources\PostsSource;
use MagnaCms\Blog\Blocks\FaqBlockResolver;
use MagnaCms\Blog\Commands\ExportContentCommand;
use MagnaCms\Blog\Commands\FlushViewsCommand;
use MagnaCms\Blog\Commands\ImportContentCommand;
use MagnaCms\Blog\Commands\ImportWxrCommand;
use MagnaCms\Blog\Commands\PublishScheduledCommand;
use MagnaCms\Blog\Commands\ReindexSearchCommand;
use MagnaCms\Blog\Editor\BlockSchema;
use MagnaCms\Blog\Editor\EditorJsSanitizer;
use MagnaCms\Blog\Filament\Pages\BlogSettingsPage;
use MagnaCms\Blog\Filament\Resources\CategoryResource;
use MagnaCms\Blog\Filament\Resources\CommentResource;
use MagnaCms\Blog\Filament\Resources\PostResource;
use MagnaCms\Blog\Filament\Resources\SeriesResource;
use MagnaCms\Blog\Filament\Resources\TagResource;
use MagnaCms\Blog\Models\Category;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Observers\CategoryObserver;
use MagnaCms\Blog\Observers\PostObserver;
use MagnaCms\Blog\Policies\PostPolicy;
use MagnaCms\Blog\Privacy\PersonalDataService;
use MagnaCms\Blog\Seo\PostSeoMeta;
use MagnaCms\Blog\Support\MetaRegistry;
use MagnaCms\Blog\Support\Spam\AkismetSpamCheck;
use MagnaCms\Blog\Support\Spam\HoneypotSpamCheck;
use MagnaCms\Blog\Support\Spam\SpamCheck;
use MagnaCms\Blog\Support\VersionedCss;
use MagnaCms\Blog\Support\VersionedJs;

class BlogPlugin extends Plugin implements HandlesPersonalData, RegistersAdminResources, RegistersBlocks, RegistersCommands, RegistersDataSources, RegistersSettingsPages, RegistersWebhookEvents
{
    public function register(): void
    {
        $this->mergeConfigFrom('config/blog.php', 'blog');

        // Shared registry of declared post-meta fields; other plugins resolve the
        // same instance to declare their own custom fields (labels, types, public).
        $this->app->singleton(MetaRegistry::class);

        /*
         * One sanitiser per app.
         *
         * Its constructor builds two HtmlSanitizers, one of which
         * materialises the whole W3C attribute table — cheap once, and
         * measurably not cheap per render. Page rendering resolves it for
         * every FAQ block on the page.
         */
        $this->app->singleton(EditorJsSanitizer::class);

        // Comment spam driver, selected from config. Honeypot is always present;
        // 'akismet' layers remote scoring on top.
        $this->app->singleton(SpamCheck::class, function ($app): SpamCheck {
            $config = $app['config']->get('blog.comments', []);
            $honeypot = new HoneypotSpamCheck((int) ($config['min_submit_seconds'] ?? 2));

            if (($config['spam_driver'] ?? 'honeypot') === 'akismet') {
                return new AkismetSpamCheck(
                    $honeypot,
                    $config['akismet']['key'] ?? null,
                    $config['akismet']['site'] ?? null,
                );
            }

            return $honeypot;
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom('resources/views', 'blog');

        /*
         * Block views go on the SHARED magna:: namespace, because the
         * block-view resolution chain (theme::blocks.X, then
         * magna::blocks.X) has no lookup in a registering plugin's own
         * namespace — so a plugin shipping a block has nowhere else to put
         * its template.
         *
         * From a directory that holds NOTHING ELSE, deliberately. Adding
         * `resources/views` here instead puts every one of this plugin's
         * views on the shared namespace, ahead of plugins that register
         * later: `filament/settings.blade.php` then shadows the Pages
         * plugin's view of the same name and breaks a screen that has
         * nothing to do with the blog. Only what is meant to be shared is
         * shared.
         */
        $this->loadViewsFrom('resources/block-views', 'magna');

        // Register the `blog::` translation namespace. The SDK base class exposes
        // loadViewsFrom / mergeConfigFrom but deliberately leaves translation
        // registration to a plain container call (it lives only on Laravel's
        // concrete Translator), so the plugin's admin strings resolve from lang/.
        /** @var Translator $translator */
        $translator = $this->app->make('translator');
        $translator->addNamespace('blog', $this->basePath.'/lang');

        FilamentAsset::register([
            VersionedJs::make('blog-editor', __DIR__.'/../dist/blog-editor.js'),
            VersionedCss::make('blog-editor', __DIR__.'/../resources/css/editor.css'),
            // WordPress-style hover flyout submenus for the admin sidebar; loaded
            // on every panel page (not on-request) so the sidebar always has them.
            VersionedCss::make('blog-admin-nav', __DIR__.'/../resources/css/admin-nav.css'),
            VersionedJs::make('blog-admin-nav', __DIR__.'/../resources/js/admin-nav.js'),
        ], package: 'magna-cms/blog');

        Post::observe(PostObserver::class);
        Category::observe(CategoryObserver::class);

        // Idiomatic Gate surface for per-record post authorization. The policy
        // delegates to PostAccess (the single source of truth), so can('update',
        // $post) / authorize() and the PostAccess helper always agree.
        Gate::policy(Post::class, PostPolicy::class);

        // Opt into the SEO plugin when it is present: posts gain sitemap + scan
        // coverage. class_exists-guarded inside, so this is a no-op with SEO absent
        // and the blog never hard-depends on it.
        PostSeoMeta::registerSource($this->app);

        /*
         * Dynamic data for the blog-posts block, through the shared resolve
         * step every block's data goes through.
         *
         * LAST, and deferred to booted(): this resolves out of the
         * container, and anything that throws earlier in boot() takes the
         * FilamentAsset::register() call above with it — which would strip
         * the post editor of its JavaScript and leave an admin screen that
         * looks broken for a reason nowhere near the blog editor. Nothing
         * here needs to run before the admin surface is registered.
         */
        $this->app->booted(function (): void {
            $resolvers = app(BlockDataResolver::class);
            $resolvers->register(app(BlogPostsBlockResolver::class));
            $resolvers->register(app(FaqBlockResolver::class));
        });

        $this->app->booted(function (): void {
            $schedule = $this->app->make(Schedule::class);

            $schedule->command('blog:publish-scheduled')
                ->everyMinute()
                ->withoutOverlapping();

            $schedule->command('blog:flush-views')
                ->everyFiveMinutes()
                ->withoutOverlapping();
        });
    }

    /**
     * @return list<class-string>
     */
    public function adminResources(): array
    {
        return [
            PostResource::class,
            CategoryResource::class,
            TagResource::class,
            SeriesResource::class,
            CommentResource::class,
        ];
    }

    /**
     * @return list<class-string>
     */
    public function commands(): array
    {
        return [
            PublishScheduledCommand::class,
            ReindexSearchCommand::class,
            FlushViewsCommand::class,
            ExportContentCommand::class,
            ImportContentCommand::class,
            ImportWxrCommand::class,
        ];
    }

    /**
     * @return list<class-string>
     */
    public function settingsPages(): array
    {
        return [
            BlogSettingsPage::class,
        ];
    }

    /**
     * @return list<string>
     */
    public function webhookEvents(): array
    {
        return [
            'blog.post.published',
            'blog.post.updated',
            'blog.post.deleted',
        ];
    }

    /**
     * Post feeds the Pages Loop block can place on any built page.
     *
     * Registered as two handles rather than one configurable source because
     * the Loop resolver passes only `limit` to a source — a choice an editor
     * makes in the block's picker has to be a handle of its own.
     *
     * @return list<DataSource>
     */
    public function dataSources(): array
    {
        return [
            new PostsSource,
            new PostsSource(featuredOnly: true),
        ];
    }

    /**
     * A block of the blog's own, in the builder's Add panel.
     *
     * The data sources above already put posts on a page, but only through
     * the Loop block and its dropdown — and "Loop" is not what somebody
     * looks for when what they want is their blog. This is the same data
     * behind a name that can be found; it reads through the same
     * PostsSource, so there is still one place deciding what a visitor may
     * see.
     *
     * @return list<BlockDefinition>
     */
    public function blocks(): array
    {
        return [
            BlockDefinition::fromArray([
                'handle' => 'blog-posts',
                'label' => 'Blog posts',
                'icon' => 'blocks:list',
                'category' => 'blog',
                'fields' => [
                    ['handle' => 'heading', 'type' => 'text', 'label' => 'Heading', 'required' => false],
                    [
                        'handle' => 'feed',
                        'type' => 'select',
                        'label' => 'Show',
                        'required' => false,
                        'default' => 'latest',
                        'options' => [
                            'latest' => 'Latest posts',
                            'featured' => 'Featured posts',
                        ],
                    ],
                    ['handle' => 'limit', 'type' => 'number', 'label' => 'How many', 'required' => false, 'default' => 6],
                ],
            ]),
            BlockDefinition::fromArray([
                'handle' => 'blog-faq',
                // Not plain "FAQ": core ships one under that exact label, and
                // two identical tiles in the panel is a choice an editor
                // cannot make. The name says what this one has that the
                // other does not.
                'label' => 'Styled FAQ',
                'icon' => 'blocks:question',
                'category' => 'blog',
                'fields' => [
                    [
                        'handle' => 'items',
                        'type' => 'repeater',
                        'label' => 'Questions',
                        'required' => false,
                        /*
                         * One blank row to start, so an inserted FAQ opens
                         * with somewhere to type. Without it the first
                         * thing an editor must do is press Add — and that
                         * click is lost if the canvas happens to be
                         * reloading from the previous edit, because the
                         * inspector has no selected node to write to for
                         * that moment.
                         */
                        'default' => [['question' => '', 'answer' => '']],
                        'fields' => [
                            ['handle' => 'question', 'type' => 'text', 'label' => 'Question', 'required' => true],
                            ['handle' => 'answer', 'type' => 'textarea', 'label' => 'Answer', 'required' => true],
                        ],
                    ],
                    [
                        'handle' => 'template',
                        'type' => 'select',
                        'label' => 'Style',
                        'required' => false,
                        'default' => 'card',
                        // Built from the schema's own list rather than a
                        // second copy of it: a template added to the editor
                        // shows up here without anyone remembering to.
                        'options' => self::faqTemplateOptions(),
                    ],
                    [
                        'handle' => 'open_first',
                        'type' => 'select',
                        'label' => 'First answer',
                        'required' => false,
                        'default' => 'no',
                        'options' => ['no' => 'Closed', 'yes' => 'Open'],
                    ],
                    [
                        'handle' => 'schema',
                        'type' => 'select',
                        'label' => 'FAQ structured data',
                        'required' => false,
                        'default' => 'yes',
                        'options' => ['yes' => 'Include', 'no' => 'Leave out'],
                    ],
                ],
            ]),
        ];
    }

    /**
     * The FAQ styles, labelled for a picker.
     *
     * @return array<string, string>
     */
    private static function faqTemplateOptions(): array
    {
        $options = [];
        foreach (BlockSchema::FAQ_TEMPLATES as $template) {
            $options[$template] = ucfirst(str_replace('-', ' ', $template));
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    public function exportPersonalData(Authenticatable $user): array
    {
        return $this->app->make(PersonalDataService::class)->export($user->getAuthIdentifier());
    }

    public function erasePersonalData(Authenticatable $user): void
    {
        $this->app->make(PersonalDataService::class)->erase($user->getAuthIdentifier());
    }
}
