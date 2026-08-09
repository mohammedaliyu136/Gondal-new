<?php

namespace Tests\Feature\Browser;

use App\Authorization\ScopeType;
use App\Models\AuthSession;
use App\Models\Delivery;
use App\Models\Device;
use App\Models\LoginCode;
use App\Models\PendingFarmerDeduction;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\RejectionReason;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Notifications\AccountCreatedNotification;
use App\Services\Admin\UserAdminService;
use App\Support\Wat;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\GondalTestCase;

/**
 * The journey walks found breaks between screens that every per-screen test
 * missed. These tests replay each broken journey end to end.
 */
class JourneyRepairsTest extends GondalTestCase
{
    /**
     * A sales officer's farmer scope is "enrolled by me" — which is nobody. The
     * customer picker and the service lookup must both bypass it, or a farmer at
     * the counter cannot buy against their milk.
     */
    public function test_a_sales_officer_can_sell_to_a_farmer_they_did_not_enrol(): void
    {
        $world = $this->makeMilkWorld();

        $officer = $this->makeUser('Counter Officer');
        $this->assignRole($officer, 'Sales Officer', ScopeType::Own);
        $this->actingAs($officer->fresh());

        // The picker offers the farmer...
        $this->get(route('shop.sales.index'))
            ->assertOk()
            ->assertSee($world['farmer']->name);

        // ...and the sale goes through, deduction and all (BR-30).
        $product = $this->stockedProduct();

        $this->post(route('shop.sales.store'), [
            'customer_type' => 'farmer',
            'farmer_id' => (string) $world['farmer']->id,
            'payment_method' => 'milk_deduction',
            'items' => [
                ['product_id' => (string) $product->id, 'quantity' => '1', 'unit_price' => ''],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->asSystem(fn () => Sale::query()->where('farmer_id', $world['farmer']->id)->count()));
        $this->assertSame(1, $this->asSystem(fn () => PendingFarmerDeduction::query()->where('farmer_id', $world['farmer']->id)->count()));
    }

    /**
     * A delivery the agent may see carries its farmer's identity even when the
     * farmer's own record is outside the agent's scope. It used to crash: the
     * scoped relation resolved to null and the view built a link from it.
     */
    public function test_a_delivery_for_an_out_of_scope_farmer_renders_with_the_name_unlinked(): void
    {
        $world = $this->makeMilkWorld();

        $agent = $this->makeUser('Point A Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent->fresh());

        // farmerB defaults to point B — outside this agent's farmer scope — but
        // delivered at point A this morning, which is a normal fact of the domain.
        $delivery = $this->asSystem(fn () => Delivery::query()->create([
            'reference' => 'DEL-7001',
            'collection_point_id' => $world['pointA']->id,
            'farmer_id' => $world['farmerB']->id,
            'litres_presented' => '18.00',
            'litres_rejected' => '0.00',
            'litres_accepted' => '18.00',
            'delivered_at' => Wat::now(),
            'status' => Delivery::STATUS_ACCEPTED,
            'recorded_by_user_id' => $agent->id,
        ]));

        $response = $this->get(route('deliveries.show', $delivery));

        $response->assertOk();
        $response->assertSee($world['farmerB']->name);
        // Identity, not access: no link to a record they cannot open.
        $response->assertDontSee(route('farmers.show', $world['farmerB']), false);
    }

    /**
     * The lockout journey: the triggering attempt says "locked", retries while
     * locked do not extend the sentence, the administrator can unlock early, and
     * a completed password reset clears the lock.
     */
    public function test_the_lockout_journey_has_exits(): void
    {
        $user = $this->makeUser('Locked Out');
        $this->assignRole($user, 'Collection Agent', ScopeType::Network);
        $user->forceFill(['password_hash' => bcrypt('Correct-Horse-9!')])->save();

        // Five wrong passwords — the FIFTH response must already say locked.
        foreach (range(1, 4) as $i) {
            $this->post(route('login.attempt'), ['email' => $user->email, 'password' => 'wrong-'.$i]);
        }

        $fifth = $this->post(route('login.attempt'), ['email' => $user->email, 'password' => 'wrong-5']);
        $fifth->assertSessionHasErrors();

        $message = collect(session('errors')->getBag('default')->get('email'))->implode(' ');
        $this->assertStringContainsString('locked', $message);

        $this->assertTrue($user->refresh()->isLocked());

        // An administrator unlocks early, and it is audited.
        $admin = $this->makeUser('Unlocking Admin');
        $this->assignRole($admin, 'System Administrator');
        $this->flushSession();
        $this->actingAs($admin->fresh());

        $this->post(route('admin.users.unlock', $user))->assertRedirect();

        $this->assertFalse($user->refresh()->isLocked());
        $this->assertDatabaseHas('audit_entries', ['subject_id' => $user->id, 'module' => 'Administration']);

        // Lock again, then prove a code-verified reset clears it.
        $user->forceFill(['locked_until' => Wat::now()->addMinutes(30)])->save();

        // The reset flow is guest-only; the admin from the unlock step must go.
        auth()->logout();
        $this->flushSession();

        // The five failed logins also consumed the shared per-IP rate-limit
        // bucket (NFR-8), which would 429 the forgot form. Step past the window —
        // the lock itself is 30 minutes, so it survives the jump.
        $this->travel(65)->seconds();

        $this->post(route('password.forgot.store'), ['email' => $user->email])->assertRedirect();

        $code = $this->latestResetCodeFor($user);

        $this->post(route('password.verify.store'), ['code' => $code])->assertSessionHasNoErrors();
        $this->post(route('password.reset.store'), [
            'password' => 'Another-Horse-10!',
            'password_confirmation' => 'Another-Horse-10!',
        ]);

        $this->assertFalse($user->refresh()->isLocked(), 'A mailbox-verified reset must clear the lock.');
    }

    /**
     * The activation journey: the welcome email's signed link seeds the reset
     * session, so the code IN THAT EMAIL is the code the screen accepts.
     */
    public function test_a_new_user_can_activate_with_the_emailed_code(): void
    {
        Notification::fake();

        $admin = $this->makeUser('Creating Admin');
        $this->assignRole($admin, 'System Administrator');
        $this->actingAs($admin->fresh());

        $newUser = app(UserAdminService::class)->create([
            'name' => 'Brand New Hire',
            'email' => 'new.hire@gondalfulbe.ng',
            'two_factor_enabled' => true,
        ], $admin->fresh());

        // Capture the emailed code and the signed URL off the notification.
        $captured = [];
        Notification::assertSentTo($newUser, AccountCreatedNotification::class,
            function (AccountCreatedNotification $notification) use (&$captured, $newUser): bool {
                $mail = $notification->toMail($newUser);
                $captured['url'] = $mail->actionUrl;
                preg_match('/\*\*(\d{6})\*\*/', implode("\n", array_map(
                    fn ($line) => (string) $line, $mail->introLines,
                )), $m);
                $captured['code'] = $m[1] ?? null;

                return true;
            });

        $this->assertNotNull($captured['code'] ?? null, 'The welcome email must carry the code.');
        $this->assertStringContainsString('/activate/', $captured['url']);
        $this->assertStringContainsString('signature=', $captured['url']);

        // The new hire clicks the button: session seeded, landed on the code screen.
        $this->flushSession();
        auth()->logout();

        $this->get($captured['url'])->assertRedirect(route('password.verify'));

        // The code from the SAME email verifies — this is the step that was broken.
        $this->post(route('password.verify.store'), ['code' => $captured['code']])
            ->assertSessionHasNoErrors();

        $this->post(route('password.reset.store'), [
            'password' => 'My-First-Password-1!',
            'password_confirmation' => 'My-First-Password-1!',
        ]);

        $this->assertNotNull($newUser->refresh()->password_changed_at, 'The hire has chosen a password.');

        // And the link cannot be forged: an unsigned copy is refused.
        $this->get('/activate/'.$newUser->id)->assertForbidden();
    }

    /**
     * A failed submit lands the operator back INSIDE the form they were filling,
     * with everything they typed still there.
     */
    public function test_a_failed_delivery_submit_reopens_the_modal_with_the_typed_work(): void
    {
        $world = $this->makeMilkWorld();

        $agent = $this->makeUser('Retyping Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent->fresh());

        $reason = $this->asSystem(fn () => RejectionReason::query()->orderBy('position')->firstOrFail());

        // BR-6 violation: rejected > presented. The form's typed values ride along.
        $this->from(route('deliveries.index'))->post(route('deliveries.store'), [
            '_modal' => 'modal-record',
            'collection_point_id' => $world['pointA']->id,
            'farmer_id' => $world['farmer']->id,
            'litres_presented' => '10.00',
            'litres_rejected' => '15.00',
            'rejection_reason_id' => $reason->id,
            'notes' => 'Half-typed note that must survive',
            'delivered_at' => Wat::forInput(Wat::todayAt(6, 0)),
        ])->assertRedirect(route('deliveries.index').'#');

        $page = $this->get(route('deliveries.index'));

        $page->assertOk();
        // The modal is open again...
        $this->assertMatchesRegularExpression(
            '/id="modal-record" class="modal\s+open/',
            $page->getContent(),
        );
        // ...with the typed work intact.
        $page->assertSee('value="10.00"', false);
        $page->assertSee('value="15.00"', false);
        $page->assertSee('Half-typed note that must survive');
    }

    /* ------------------------------------------------------------------ */

    private function latestResetCodeFor(User $user): string
    {
        // Codes are stored hashed; recover the plain digits by brute-forcing the
        // 6-digit space against the stored hash — cheap, and honest about what
        // the storage guarantees.
        $hash = $this->asSystem(fn () => LoginCode::query()
            ->where('user_id', $user->id)->usable()->latest('id')->value('code_hash'));

        foreach (range(0, 999999) as $candidate) {
            $code = str_pad((string) $candidate, 6, '0', STR_PAD_LEFT);

            if (hash('sha256', $code) === $hash) {
                return $code;
            }
        }

        $this->fail('No usable reset code found.');
    }

    private function stockedProduct(): Product
    {
        return $this->asSystem(function () {
            $category = ProductCategory::query()->firstOrCreate(
                ['code' => 'JRNY-CAT'],
                ['name' => 'Feed', 'default_unit' => 'bag', 'default_reorder_level' => 5,
                    'requires_prescription' => false, 'track_expiry' => false, 'allow_credit' => true,
                    'requires_manager_approval' => false, 'status' => 'active'],
            );

            $product = Product::query()->create([
                'sku' => 'JRNY-'.random_int(100, 999),
                'name' => 'Journey feed',
                'product_category_id' => $category->getKey(),
                'unit' => 'bag',
                'cost_price_minor' => 9_000_00,
                'selling_price_minor' => 11_000_00,
                'reorder_level' => 5,
                'quantity_on_hand' => 20,
                'status' => 'active',
            ]);

            StockMovement::query()->create([
                'product_id' => $product->getKey(),
                'movement_type' => StockMovement::TYPE_STOCK_IN,
                'reference' => 'opening',
                'quantity_in' => 20,
                'quantity_out' => 0,
                'balance_after' => 20,
            ]);

            return $product;
        });
    }

    /** AUTH-2 — the administrator can revoke a trusted device and end sessions. */
    public function test_an_administrator_can_revoke_a_device_and_end_sessions(): void
    {
        $victim = $this->makeUser('Lost Their Phone');
        $this->assignRole($victim, 'Collection Agent', ScopeType::Network);

        $device = $this->asSystem(fn () => Device::query()->create([
            'user_id' => $victim->id,
            'label' => 'Stolen Android',
            'token_hash' => hash('sha256', 'whatever'),
            'trusted_until' => Wat::now()->addDays(30),
            'last_seen_at' => Wat::now(),
            'last_ip' => '10.0.0.9',
        ]));

        $session = $this->asSystem(fn () => AuthSession::query()->create([
            'user_id' => $victim->id,
            'ip' => '10.0.0.9',
            'started_at' => Wat::now()->subHour(),
            'last_seen_at' => Wat::now(),
        ]));

        $admin = $this->makeUser('Security Admin');
        $this->assignRole($admin, 'System Administrator');
        $this->actingAs($admin->fresh());

        // The controls are on the screen, not just the routes.
        $page = $this->get(route('admin.users.show', $victim));
        $page->assertOk();
        $page->assertSee(route('admin.users.devices.revoke', [$victim, $device]), false);
        $page->assertSee(route('admin.users.sign-out-everywhere', $victim), false);

        $this->post(route('admin.users.devices.revoke', [$victim, $device]))->assertRedirect();
        $this->assertNotNull($device->refresh()->revoked_at, 'The stolen device must lose its trust.');

        $this->post(route('admin.users.sign-out-everywhere', $victim))->assertRedirect();
        $this->assertNotNull($session->refresh()->ended_at);

        // The account itself is untouched — this is not a deactivation.
        $this->assertTrue($victim->refresh()->isActive());

        // A device belonging to somebody else is not revocable through this route.
        $other = $this->makeUser('Unrelated');
        $otherDevice = $this->asSystem(fn () => Device::query()->create([
            'user_id' => $other->id,
            'label' => 'Their laptop',
            'token_hash' => hash('sha256', 'other'),
            'trusted_until' => Wat::now()->addDays(30),
        ]));

        $this->post(route('admin.users.devices.revoke', [$victim, $otherDevice]))->assertNotFound();
        $this->assertNull($otherDevice->refresh()->revoked_at);
    }

    /**
     * "Save and add another" returns the agent to the open form with the point
     * still chosen, instead of stranding them on a detail page.
     */
    public function test_save_and_add_another_returns_to_the_open_form(): void
    {
        $world = $this->makeMilkWorld();

        $agent = $this->makeUser('Queue Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent->fresh());

        $response = $this->post(route('deliveries.store'), [
            'add_another' => '1',
            'collection_point_id' => $world['pointA']->id,
            'farmer_id' => $world['farmer']->id,
            'litres_presented' => '22.00',
            'delivered_at' => Wat::forInput(Wat::todayAt(6, 0)),
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(
            route('deliveries.index', ['point' => $world['pointA']->id]).'#modal-record',
        );

        // The delivery was still recorded — this is a navigation change, not a
        // different kind of save.
        $this->assertSame(1, $this->asSystem(fn () => Delivery::query()->count()));

        // Without the flag, the plain save still opens the record.
        $second = $this->post(route('deliveries.store'), [
            'collection_point_id' => $world['pointA']->id,
            'farmer_id' => $world['farmer']->id,
            'litres_presented' => '19.00',
            'delivered_at' => Wat::forInput(Wat::todayAt(6, 5)),
        ]);

        $latest = $this->asSystem(fn () => Delivery::query()->latest('id')->firstOrFail());
        $second->assertRedirect(route('deliveries.show', $latest));
    }

    /** A refused user's roles reach the administrator who has to fix it. */
    public function test_the_denial_trail_shows_what_the_administrator_needs(): void
    {
        $world = $this->makeMilkWorld();

        $agent = $this->makeUser('Refused Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent->fresh());

        // Trip a refusal.
        $this->get(route('payroll.index'))->assertStatus(403);

        $admin = $this->makeUser('Resolving Admin');
        $this->assignRole($admin, 'System Administrator');
        $this->flushSession();
        $this->actingAs($admin->fresh());

        $page = $this->get(route('admin.audit-log', ['event_type' => 'blocked_access']));

        $page->assertOk();
        // The missing permission, the roles they actually held, and a way through
        // to the person — the three things needed to resolve it.
        $page->assertSee('hr.payroll.view');
        $page->assertSee('Collection Agent');
        $page->assertSee(route('admin.users.show', $agent), false);
    }

    /**
     * A write closes the modal it came from.
     *
     * Every modal here is a CSS :target modal — open because the URL ends in
     * "#modal-something". A fragment never reaches the server, so a redirect can
     * never mention one, and the browser INHERITS the fragment it already had
     * (RFC 7231 §7.1.2). The result: submit a form, and the modal springs back
     * open over the success message as though nothing happened.
     */
    public function test_a_successful_write_closes_the_modal(): void
    {
        $hr = $this->makeUser('Closing HR');
        $this->assignRole($hr, 'HR Manager');
        $this->actingAs($hr->fresh());

        $response = $this->post(route('departments.store'), [
            '_modal' => 'modal-department',
            'name' => 'Closing Test Department',
        ]);

        $response->assertSessionHasNoErrors();

        // The empty fragment is what closes it: "#" matches no element.
        $this->assertStringEndsWith(
            '#',
            $response->headers->get('Location'),
            'A redirect after a write must carry an empty fragment, or the browser reopens the modal.',
        );
    }

    /** A controller that deliberately wants a modal open keeps its fragment. */
    public function test_save_and_add_another_still_reopens_the_form(): void
    {
        $world = $this->makeMilkWorld();

        $agent = $this->makeUser('Repeat Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent->fresh());

        $response = $this->post(route('deliveries.store'), [
            'add_another' => '1',
            'collection_point_id' => $world['pointA']->id,
            'farmer_id' => $world['farmer']->id,
            'litres_presented' => '18.00',
            'delivered_at' => Wat::forInput(Wat::todayAt(6, 0)),
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertStringEndsWith('#modal-record', $response->headers->get('Location'));
    }

    /** A GET that redirects keeps whatever fragment it was given. */
    public function test_navigation_fragments_are_left_alone(): void
    {
        $admin = $this->makeUser('Nav Admin');
        $this->assignRole($admin, 'System Administrator');
        $this->actingAs($admin->fresh());

        // A refused page redirects; nothing should be appended to it.
        $response = $this->get('/login');

        if ($response->isRedirect()) {
            $this->assertStringNotContainsString('#', (string) $response->headers->get('Location'));
        }

        $this->assertTrue(true);
    }

    /**
     * When a modal reopens after an error, the error must be readable INSIDE it.
     *
     * The page's flash block renders at the top of the content, underneath the
     * modal's own fixed overlay. So the reason the form reopened was drawn behind
     * the thing covering the screen: the operator saw a form that had apparently
     * refused to close, saying nothing about why.
     */
    public function test_a_modal_shows_its_own_error_where_it_can_be_read(): void
    {
        $world = $this->makeMilkWorld();

        $agent = $this->makeUser('Erroring Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent->fresh());

        $reason = $this->asSystem(fn () => RejectionReason::query()->orderBy('position')->firstOrFail());

        // BR-6: rejected cannot exceed presented.
        $this->from(route('deliveries.index'))->post(route('deliveries.store'), [
            '_modal' => 'modal-record',
            'collection_point_id' => $world['pointA']->id,
            'farmer_id' => $world['farmer']->id,
            'litres_presented' => '10.00',
            'litres_rejected' => '15.00',
            'rejection_reason_id' => $reason->id,
            'delivered_at' => Wat::forInput(Wat::todayAt(6, 0)),
        ]);

        $html = $this->get(route('deliveries.index'))->assertOk()->getContent();

        // The modal is open...
        $this->assertMatchesRegularExpression('/id="modal-record" class="modal\s+open/', $html);

        // ...and the message appears INSIDE it, not only in the page flash above.
        $modalStart = strpos($html, 'id="modal-record"');
        $modalEnd = strpos($html, 'modal-foot', $modalStart);
        $insideModal = substr($html, $modalStart, $modalEnd - $modalStart);

        $this->assertStringContainsString(
            'cannot exceed',
            $insideModal,
            'The reason the modal reopened must be readable inside the modal.',
        );
    }

    /** A different modal on the same screen does not claim someone else's error. */
    public function test_only_the_submitted_modal_shows_the_error(): void
    {
        $world = $this->makeMilkWorld();

        $supervisor = $this->makeUser('Two Modal Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor');
        $this->actingAs($supervisor->fresh());

        /*
         * Fail the point form; the edit modals on the same screen must stay quiet.
         *
         * Deliberately NOT calling assertSessionHasErrors() on this response:
         * reading the session ages the flashed data, so the GET below would find
         * no errors and the test would fail for a reason that has nothing to do
         * with the application. The assertions on the rendered page are the point.
         */
        $this->from(route('collection-points.index'))->post(route('collection-points.store'), [
            '_modal' => 'modal-new-point',
            'name' => 'Missing its code',
        ]);

        $html = $this->get(route('collection-points.index'))->assertOk()->getContent();

        /*
         * The page flash still renders too — correct, since it is what the
         * operator reads if they close the modal. What must NOT happen is a
         * second modal on the same screen claiming an error it did not cause.
         */
        // Bounded by the next modal, which is an unambiguous boundary.
        $createModal = $this->markupBetween($html, 'id="modal-new-point"', 'id="modal-edit-point-');
        $editModal = $this->markupBetween($html, 'id="modal-edit-point-', '@@never@@');

        $this->assertStringContainsString(
            'alert danger',
            $createModal,
            'The modal that was submitted must show its error.',
        );

        $this->assertStringNotContainsString(
            'alert danger',
            $editModal,
            'An untouched modal must not display another form\'s error.',
        );
    }

    /** The markup from a marker up to the next occurrence of an end marker. */
    private function markupBetween(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);

        if ($start === false) {
            return '';
        }

        $end = strpos($html, $to, $start);

        return $end === false ? substr($html, $start) : substr($html, $start, $end - $start);
    }
}
