<?php
declare(strict_types=1);

namespace SmartPRS\People\Application;

use Illuminate\Support\Facades\Log;

/** CLEAN FIXTURE — identifiers in the log, values behind authorisation. */
final class OnboardingService
{
    public function complete(array $person): void
    {
        // PII goes to storage, not to a log line.
        $this->repo->store(['aadhaar' => $person['aadhaar'], 'pan' => $person['pan']]);

        Log::info('onboarded', ['person_id' => $person['id'], 'tenant_id' => $person['tenant_id']]);
        $this->logger->debug('bank details recorded', ['person_id' => $person['id']]);
    }
}
