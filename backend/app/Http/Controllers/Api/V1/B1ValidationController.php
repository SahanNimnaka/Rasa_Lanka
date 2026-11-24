<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\B1ValidationRequest;
use App\Services\Annotation\B1ValidationAdapter;
use Illuminate\Http\JsonResponse;

class B1ValidationController extends Controller
{
    public function __construct(private readonly B1ValidationAdapter $adapter)
    {
    }

    public function validate(B1ValidationRequest $request): JsonResponse
    {
        $result = $this->adapter->validateRecord($request->validated('record'));

        if ($result->httpStatus === 503) {
            return self::error(
                'B1_VALIDATION_UNAVAILABLE',
                'The B1 validation service is currently unavailable.',
                503,
                $result->payload['infrastructure_error'] ?? null,
                ['run_id' => $result->payload['run_id'] ?? null]
            );
        }

        return self::success($result->payload, ['run_id' => $result->payload['run_id'] ?? null], 200);
    }
}
