<?php
declare(strict_types=1);

namespace SmartPRS\People\Application;

use Illuminate\Support\Facades\Log;

/** VIOLATING FIXTURE — SmartPRS 2 writes these lines to a web-readable log. */
final class OnboardingService
{
    public function complete(array $person): void
    {
        Log::info('onboarded', ['aadhaar' => $person['aadhaar'], 'pan' => $person['pan']]);
        $this->logger->debug('bank set up: ' . $person['bank_acc'] . '/' . $person['ifsc']);
        logger('otp issued ' . $otp);
    }
}
