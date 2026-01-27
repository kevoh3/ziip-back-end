<?php


namespace App\Services\Choice;

use App\Support\ChoiceSigner;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ChoiceOnboardingService
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

    public static function fromConfig(): self
    {
        $cfg = config('services.choice');

        return new self(
            $cfg['base_url'],
            $cfg['sender'],
            $cfg['private_key'],
            $cfg['locale'],
            $cfg['timeout'],
        );
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
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
            'timestamp' => now()->format('YmdHis'),
            'params' => $params,
        ];
    }

    private function postSigned(string $path, array $params): array
    {
        $payload = $this->envelope($params);
        $signed = ChoiceSigner::sign($payload, $this->privateKey);

        $res = $this->http()->post($path, $signed);
        $res->throw();

        $json = $res->json();
        return is_array($json) ? $json : ['raw' => $res->body()];
    }

    // ---- Onboarding endpoints ----

    public function submitOnboarding(array $kycData): array
    {
        return $this->postSigned('/onboarding/submitOnboardingRequest', $kycData);
    }

    public function uploadMedia(string $onboardingRequestId, string $mediaType, string $mediaBase64): array
    {
        return $this->postSigned('/onboarding/uploadMedia', [
            'onboardingRequestId' => $onboardingRequestId,
            'mediaType' => $mediaType,
            'mediaBase64' => $mediaBase64,
        ]);
    }

    public function status(string $onboardingRequestId): array
    {
        return $this->postSigned('/onboarding/getOnboardingStatus', [
            'onboardingRequestId' => $onboardingRequestId,
        ]);
    }
}
