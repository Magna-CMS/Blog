<?php

declare(strict_types=1);

namespace MagnaCms\Blog;

use Filament\Support\Facades\FilamentAsset;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Translation\Translator;
use Magna\Contracts\HandlesPersonalData;
use Magna\Contracts\RegistersAdminResources;
use Magna\Contracts\RegistersCommands;
use Magna\Contracts\RegistersSettingsPages;
use Magna\Contracts\RegistersWebhookEvents;
use Magna\Plugins\Plugin;
use MagnaCms\Blog\Commands\ExportContentCommand;
use MagnaCms\Blog\Commands\FlushViewsCommand;
use MagnaCms\Blog\Commands\ImportContentCommand;
use MagnaCms\Blog\Commands\ImportWxrCommand;
use MagnaCms\Blog\Commands\PublishScheduledCommand;
use MagnaCms\Blog\Commands\ReindexSearchCommand;
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

class BlogPlugin extends Plugin implements HandlesPersonalData, RegistersAdminResources, RegistersCommands, RegistersSettingsPages, RegistersWebhookEvents
{
    public function register(): void
    {
        $this->mergeConfigFrom('config/blog.php', 'blog');

        // Shared registry of declared post-meta fields; other plugins resolve the
        // same instance to declare their own custom fields (labels, types, public).
        $this->app->singleton(MetaRegistry::class);

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
