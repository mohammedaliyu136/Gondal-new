<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * ST-1 — an illegal state transition, or any violated business rule, returns 422
 * carrying the rule ID that was violated. Never a bare 500, never a silent pass.
 */
class RuleViolationException extends RuntimeException
{
    /**
     * @param  string  $ruleId  e.g. "BR-11"
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $ruleId,
        string $message,
        public readonly array $context = [],
        public readonly ?string $field = null,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function make(string $ruleId, string $message, array $context = [], ?string $field = null): self
    {
        return new self($ruleId, $message, $context, $field);
    }

    /**
     * The declared return type has to include RedirectResponse.
     *
     * It did not, and the web branch returns exactly that — so every business-rule
     * violation raised on a web request died with a TypeError and the operator got
     * a 500 page instead of their message. `Illuminate\Http\RedirectResponse` is
     * not a subclass of `Illuminate\Http\Response`; both descend from Symfony's,
     * which is why the signature looked plausible and was wrong.
     *
     * The whole rule suite missed it because it reaches the rules through the
     * services and never renders the exception. RuleViolationResponseTest now
     * covers this path from the browser's side.
     */
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        $payload = [
            'message' => $this->getMessage(),
            'rule' => $this->ruleId,
            'context' => $this->context,
        ];

        // The API carries the rule ID as its own key, so an integrator can branch
        // on it. It is not concatenated into the human message, which would make
        // the same fact appear twice in one sentence.
        if ($this->field !== null) {
            $payload['errors'] = [$this->field => [$this->getMessage()]];
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json($payload, 422);
        }

        /*
         * On the web the operator gets the message alone. They have been stopped
         * mid-task and need to know what to do next; "[BR-11]" tells them nothing
         * they can act on, and the screen was printing it twice — once here and
         * once as a headline in the flash partial.
         *
         * The ID still travels in the session so a support screen or a log reader
         * can recover which rule fired.
         */
        return back()
            ->withInput()
            ->withErrors([($this->field ?? 'rule') => $this->getMessage()])
            ->with('rule_violation', $this->ruleId);
    }
}
