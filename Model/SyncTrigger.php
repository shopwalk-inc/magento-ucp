<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Model;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Phrase;
use Magento\Framework\Webapi\Exception as WebapiException;
use Shopwalk\Ucp\Api\SyncTriggerInterface;

/**
 * Implements POST /rest/V1/ucp/sync/trigger. HMAC-SHA256 is verified against
 * the raw request body using the webhook_secret stored at activation time.
 * Magento's webapi.xml marks this route anonymous because auth happens
 * here, inside the service.
 */
class SyncTrigger implements SyncTriggerInterface
{
    public function __construct(
        private readonly HttpRequest $request,
        private readonly Config $config,
        private readonly SyncQueue $queue,
    ) {}

    public function trigger(array $data): array
    {
        if (!$this->config->isEnabled()) {
            throw new WebapiException(new Phrase('UCP disabled'), 0, WebapiException::HTTP_FORBIDDEN);
        }

        $secret = $this->config->getWebhookSecret();
        if ($secret === '') {
            throw new WebapiException(
                new Phrase('Store is not linked to Shopwalk'),
                0,
                WebapiException::HTTP_FORBIDDEN
            );
        }

        $rawBody = (string) $this->request->getContent();
        $received = (string) $this->request->getHeader('X-Shopwalk-Signature');
        if ($received === '') {
            throw new WebapiException(
                new Phrase('Missing X-Shopwalk-Signature'),
                0,
                WebapiException::HTTP_UNAUTHORIZED
            );
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);
        if (!hash_equals($expected, $received)) {
            throw new WebapiException(
                new Phrase('Invalid signature'),
                0,
                WebapiException::HTTP_UNAUTHORIZED
            );
        }

        $count = $this->queue->fullSync();
        // Drain one batch immediately so a scheduled trigger makes visible
        // progress without waiting 5 minutes for the cron.
        $this->queue->flush('full');

        return [
            'status' => 'queued',
            'product_count' => $count,
            'reason' => (string) ($data['reason'] ?? 'scheduled'),
        ];
    }
}
