<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\IntegrationException;
use App\Exceptions\SchedulingException;
use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared HTTP behaviour for the scheduling API.
 *
 * Three things live here because every endpoint has to answer them the same
 * way.
 *
 * THE EXCEPTION MAP. The domain services throw rather than return failures.
 * SchedulingException is always the caller's fault — a shift that ends before
 * it starts, a day close over hours nobody approved — so it is 422, and its
 * context() goes back verbatim because that is the machine-readable half the
 * caller needs. IntegrationException is the vendor's fault, so it is 502 with
 * retryable = isTransient(): the caller may repeat a transient failure
 * unchanged and must not repeat a permanent one.
 *
 * THE PAY GATE. See wantsCost().
 *
 * WHO IS ACTING. See actingUserId().
 */
abstract class ApiController extends Controller
{
    /**
     * Run a service call and render the two exception types the domain throws.
     *
     * The closure returns the success response, so each endpoint keeps its own
     * status code where you can see it instead of hiding it in a helper.
     */
    protected function attempt(Closure $work): JsonResponse
    {
        try {
            return $work();
        } catch (SchedulingException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'context' => $e->context(),
            ], 422);
        } catch (IntegrationException $e) {
            // context() is identifiers only. NOT responseExcerpt — a vendor
            // error body echoes the record we sent it, and that record carries
            // birth dates and pay rates.
            return response()->json([
                'message' => $e->getMessage(),
                'retryable' => $e->isTransient(),
                'context' => $e->context(),
            ], 502);
        }
    }

    /**
     * Is the caller explicitly asking for pay data? ?include=cost
     *
     * employee_pay_histories is the most sensitive table in the schema, so
     * anything derived from an individual's rate — a per-shift estimate, a
     * per-employee cost line, a roster rate — is opt-in. Store-level totals are
     * not gated: a shift manager needs to know what the day costs, just not
     * what a colleague earns.
     *
     * TODO(authorisation): THIS IS NOT A PERMISSION CHECK, and must not be
     * mistaken for one. This service has no auth layer at all yet — no guard,
     * no token middleware, no policies — so a query parameter is the only thing
     * standing here, and anyone who asks gets the data. When a guard lands,
     * this method is the gate: "did they ask for cost" has to become "may this
     * user see cost", and the parameter demoted to a hint about what to
     * include. It is one method on purpose, so that change is one edit rather
     * than an audit of every resource.
     */
    protected function wantsCost(Request $request): bool
    {
        $includes = array_map('trim', explode(',', (string) $request->query('include', '')));

        return in_array('cost', $includes, true);
    }

    /**
     * Whoever is acting, for the created_by / approved_by / decided_by columns.
     *
     * Null today, and every one of those columns is nullable for exactly that
     * reason. It is read from the resolved user and never from the request
     * body: a caller who could name themselves could name anyone.
     */
    protected function actingUserId(Request $request): ?int
    {
        $id = $request->user()?->id;

        return $id === null ? null : (int) $id;
    }
}
