<?php


namespace App\Services;

use App\Support\ChoiceSigner;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ChoiceBankService
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $sender,
        private readonly string $privateKey,
        private readonly string $locale,
        private readonly int    $timeout,
    )
    {
    }

    public static function make(): self
    {
        $cfg = config('services.choice');

        return new self(
            rtrim($cfg['base_url'], '/'),
            $cfg['sender'],
            $cfg['private_key'],
            $cfg['locale'],
            $cfg['timeout'],
        );
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->acceptJson()
            ->asJson();
    }

    private function envelope(array $params): array
    {
        return [
            'requestId' => (string)Str::uuid(),
            'sender' => $this->sender,
            'locale' => $this->locale,
            'timestamp' => now()->format('YmdHis'), // common pattern in their example
            'params' => $params,
        ];
    }

    private function postSigned(string $path, array $params): array
    {
        $payload = $this->envelope($params);

        // Sign (adds salt + signature)
        $signed = ChoiceSigner::sign($payload, $this->privateKey);

        $res = $this->http()->post($path, $signed);

        // Throw on non-2xx
        $res->throw();

        $body = $res->json();

        // Optional: verify response signature if present
        if (is_array($body) && isset($body['signature'])) {
            $ok = ChoiceSigner::verify($body, $this->privateKey);
            if (!$ok) {
                throw new \RuntimeException('Choice response signature verification failed');
            }
        }

        return is_array($body) ? $body : ['raw' => $res->body()];
    }

    // ---- Onboarding endpoints ----

    public function submitOnboardingRequest(array $kycData): array
    {
        return $this->postSigned('/onboarding/submitOnboardingRequest', $kycData);
    }

    public function uploadMedia(string $onboardingRequestId, string $mediaType, string $base64): array
    {
        return $this->postSigned('/onboarding/uploadMedia', [
            'onboardingRequestId' => $onboardingRequestId,
            'mediaType' => $mediaType,
            'mediaBase64' => $base64,
        ]);
    }

    public function getOnboardingStatus(string $onboardingRequestId): array
    {
        return $this->postSigned('/onboarding/getOnboardingStatus', [
            'onboardingRequestId' => $onboardingRequestId,
        ]);
    }

    public function getUserKyc(string $onboardingRequestId): array
    {
        return $this->postSigned('/onboarding/getUserKyc', [
            'onboardingRequestId' => $onboardingRequestId,
        ]);
    }

    public function getKycMediaList(string $onboardingRequestId): array
    {
        return $this->postSigned('/onboarding/personal/getKycMediaList', [
            'onboardingRequestId' => $onboardingRequestId,
        ]);
    }

    public function getOnboardingRequestIdByMobile(string $mobile, string $countryCode = '254'): array
    {
        return $this->postSigned('/onboarding/getOnboardingRequestId', [
            'mobile' => $mobile,
            'countryCode' => $countryCode,
        ]);
    }
}
