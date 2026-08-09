<?php

namespace Tests\Feature\Browser;

use App\Authorization\ScopeType;
use App\Models\Delivery;
use App\Models\RejectionReason;
use App\Support\Wat;
use Tests\GondalTestCase;

/**
 * What an operator actually receives when they trip a business rule.
 *
 * The rule suite proves the rules fire. It reaches them through the services, so
 * it never exercises RuleViolationException::render() on the web path — which is
 * the only thing the operator ever sees.
 */
class RuleViolationResponseTest extends GondalTestCase
{
    /** A violated rule sends the operator back with the message, and no rule ID. */
    public function test_a_web_rule_violation_redirects_back_with_a_plain_message(): void
    {
        $world = $this->makeMilkWorld();

        $agent = $this->makeUser('Violating Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent->fresh());

        $reason = RejectionReason::query()->orderBy('position')->firstOrFail();

        // BR-6 — rejected volume cannot exceed the volume presented.
        $response = $this->from(route('deliveries.index'))->post(route('deliveries.store'), [
            'collection_point_id' => $world['pointA']->id,
            'farmer_id' => $world['farmer']->id,
            'litres_presented' => '10.00',
            'litres_rejected' => '15.00',
            'rejection_reason_id' => $reason->id,
            'delivered_at' => Wat::forInput(Wat::todayAt(6, 0)),
        ]);

        $response->assertRedirect(route('deliveries.index'));
        $response->assertSessionHasErrors();

        $errors = session('errors')->getBag('default')->all();

        $this->assertNotEmpty($errors);

        foreach ($errors as $message) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(?:BR|ST|DM|NFR|AUTH|SCOPE|ROLE)-\d+\b/',
                $message,
                'An operator must not be shown a rule identifier: '.$message,
            );
        }

        // Nothing was written.
        $this->assertSame(0, $this->asSystem(fn () => Delivery::query()->count()));
    }

    /** The API gets the same refusal, with the rule ID as its own field. */
    public function test_the_api_receives_the_rule_identifier_as_a_separate_field(): void
    {
        $world = $this->makeMilkWorld();

        $agent = $this->makeUser('Violating API Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent->fresh());

        $reason = RejectionReason::query()->orderBy('position')->firstOrFail();

        $response = $this->postJson('/api/deliveries', [
            'collection_point_id' => $world['pointA']->id,
            'farmer_id' => $world['farmer']->id,
            'litres_presented' => '10.00',
            'litres_rejected' => '15.00',
            'rejection_reason_id' => $reason->id,
            'delivered_at' => Wat::todayAt(6, 0)->toIso8601String(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'rule', 'context']);

        $payload = $response->json();

        $this->assertMatchesRegularExpression('/^[A-Z]+-\d+$/', $payload['rule']);
        $this->assertStringNotContainsString($payload['rule'], $payload['message']);
    }
}
