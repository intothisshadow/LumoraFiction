<?php

/**
 * The opt-in, off-by-default anonymous install counter: a random install ID, the Lumora Fiction version and the PHP version.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use Closure;
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Update\HttpPoster;
use RuntimeException;
use Throwable;

/**
 * Nothing is sent until an administrator turns it on. The payload is exactly three fields, and the endpoint is not
 * the update source, so the two features stay independent. The attempt time is written whether the ping worked or
 * not, so an unreachable endpoint is tried again next interval and never on every page. The caller decides when it
 * is safe to wait on the network (the admin area only); this class has a short timeout and no retry loop.
 */
final class InstallPingService
{
    public const ENDPOINT = 'https://coding.unloved-heart.net/lumorafiction/install-tracking-server/ping.php';

    /** About a month, in seconds. */
    public const INTERVAL = 2592000;

    public const TIMEOUT = 5;

    public const DEFAULTS = [
        'ping.enabled' => false,
        'ping.uuid' => '',
        'ping.site_hash' => '',
        'ping.last_attempt' => 0,
    ];

    public function __construct(
        private readonly Configuration $config,
        private readonly HttpPoster $transport,
        private readonly string $version,
        private readonly string $endpoint = self::ENDPOINT,
        private readonly string $phpVersion = PHP_VERSION,
        private readonly ?Closure $siteAddress = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->setting('ping.enabled') === true;
    }

    /**
     * The install ID. One ID belongs to one site address: when the stored fingerprint of the address differs from the
     * current one (a backup restored on another host, a copied folder, a moved domain) a new ID is made, so two sites
     * never report under the same one. The fingerprint stays on the site and is never sent.
     */
    public function getOrCreateUuid(): string
    {
        $uuid = $this->config->setting('ping.uuid', '');
        $stored = $this->config->setting('ping.site_hash', '');
        $now = $this->addressHash();

        if (is_string($uuid) && self::isUuid($uuid) && ($stored === $now || $stored === '')) {
            if ($stored === '') {
                $this->config->setSetting('ping.site_hash', $now);
            }

            return $uuid;
        }

        $uuid = self::makeUuid();
        $this->config->setSetting('ping.uuid', $uuid);
        $this->config->setSetting('ping.site_hash', $now);

        return $uuid;
    }

    /**
     * The ID as it is now, for the settings screen; null while none has been made. Writes nothing.
     */
    public function currentUuid(): ?string
    {
        $uuid = $this->config->setting('ping.uuid', '');
        $stored = $this->config->setting('ping.site_hash', '');

        return is_string($uuid) && self::isUuid($uuid) && ($stored === $this->addressHash() || $stored === '') ? $uuid : null;
    }

    /**
     * When the last attempt was made, successful or not, as a Unix time; null when there has been none.
     */
    public function lastAttempt(): ?int
    {
        $time = $this->config->setting('ping.last_attempt', 0);

        return is_int($time) && $time > 0 ? $time : null;
    }

    public function isDue(?int $now = null): bool
    {
        $last = $this->lastAttempt();

        return $this->isEnabled() && ($last === null || ($now ?? time()) - $last >= self::INTERVAL);
    }

    /**
     * Sends a ping when it is on and one is due. Never throws: a failure is recorded as an attempt and shown nowhere.
     */
    public function maybeSendPing(?int $now = null): bool
    {
        if (!$this->isDue($now)) {
            return false;
        }

        return $this->attempt();
    }

    /**
     * The ping that goes out straight after an administrator turns the feature on, whatever the interval says.
     */
    public function sendAfterEnabling(): bool
    {
        return $this->isEnabled() && $this->attempt();
    }

    /**
     * Sends a ping now, whether or not one is due (this is the test button, which the screen offers only while the switch is on).
     *
     * @throws RuntimeException with a sentence an administrator can read, when the request fails or is refused
     */
    public function sendPing(): void
    {
        $payload = json_encode([
            'install_uuid' => $this->getOrCreateUuid(),
            'version' => $this->version,
            'php_version' => $this->phpVersion,
        ], JSON_THROW_ON_ERROR);

        $status = 0;
        $failure = null;

        try {
            $status = $this->transport->postJson($this->endpoint, $payload, self::TIMEOUT);
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        try {
            $this->config->setSetting('ping.last_attempt', time());
        } catch (Throwable) {
            // A settings table that cannot be written must not turn a ping into an error page.
        }

        if ($failure !== null) {
            throw new RuntimeException('The ping could not be sent. ' . $failure->getMessage(), 0, $failure);
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('The counting server did not accept the ping (it answered ' . $status . ').');
        }
    }

    private function attempt(): bool
    {
        try {
            $this->sendPing();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function addressHash(): string
    {
        $address = $this->siteAddress !== null ? ($this->siteAddress)() : (string) $this->config->get('site_url', '');

        return hash('sha256', strtolower(rtrim($address, '/')));
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1;
    }

    private static function makeUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}
