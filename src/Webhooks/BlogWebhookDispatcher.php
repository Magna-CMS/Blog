<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Webhooks;

use Illuminate\Support\Facades\Log;
use Magna\Webhooks\Jobs\DispatchWebhookJob;
use Magna\Webhooks\WebhookDelivery;
use Magna\Webhooks\WebhookSubscription;
use Throwable;

/**
 * Fires the Blog plugin's webhook events into the core delivery pipeline. Core
 * only auto-dispatches webhooks for its own content/media events, so the plugin
 * triggers its own event keys here, reusing the core subscription/delivery/job
 * classes. Failures are logged, never thrown, so a webhook problem cannot break
 * a post save.
 */
final class BlogWebhookDispatcher
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function fire(string $eventKey, array $data): void
    {
        try {
            $subscriptions = WebhookSubscription::query()->where('active', true)->get();

            foreach ($subscriptions as $subscription) {
                if (! $subscription->subscribesTo($eventKey)) {
                    continue;
                }

                $delivery = WebhookDelivery::create([
                    'subscription_id' => $subscription->id,
                    'event' => $eventKey,
                    'payload' => array_merge($data, [
                        'event' => $eventKey,
                        'timestamp' => now()->toIso8601String(),
                    ]),
                    'status' => 'pending',
                    'attempts' => 0,
                ]);

                DispatchWebhookJob::dispatch($delivery->id);
            }
        } catch (Throwable $exception) {
            Log::warning('Blog webhook dispatch failed for ['.$eventKey.']: '.$exception->getMessage());
        }
    }
}
