<?php

declare(strict_types=1);

use App\Mail\RaceWeekendPreviewMail;
use App\Models\NewsletterSubscriber;
use App\Models\Race;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Gmail изисква one-click отписване (RFC 8058) от масовите изпращачи. Линкът
 * във футъра не се брои — липсва ли хедърът, писмата отиват в спам въпреки
 * чистите SPF/DKIM/DMARC. И обратното: хедър, който сочи към маршрут без POST,
 * е по-лош от липсващ, защото доставчикът отчита счупено отписване.
 */
it('обявява one-click отписване към потребителския signed линк', function () {
    $url = URL::signedRoute('newsletter.user-unsubscribe', ['user' => 1]);

    $mail = new RaceWeekendPreviewMail(
        Race::factory()->create(),
        program: [],
        userUnsubscribeUrl: $url,
    );

    $headers = $mail->headers();

    expect($headers->text['List-Unsubscribe'])->toBe("<{$url}>")
        ->and($headers->text['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click');
});

it('обявява one-click отписване към токена на абонат без акаунт', function () {
    $mail = new RaceWeekendPreviewMail(
        Race::factory()->create(),
        program: [],
        unsubscribeToken: 'tok-123',
    );

    expect($mail->headers()->text['List-Unsubscribe'])
        ->toBe('<'.route('newsletter.unsubscribe', ['token' => 'tok-123']).'>');
});

it('не обявява one-click, когато няма адрес за отписване', function () {
    $mail = new RaceWeekendPreviewMail(Race::factory()->create(), program: []);

    expect($mail->headers()->text)->not->toHaveKey('List-Unsubscribe');
});

it('отписва абонат при POST от пощенския доставчик, без CSRF токен', function () {
    $subscriber = NewsletterSubscriber::create([
        'email' => 'abonat@example.bg',
        'unsubscribe_token' => 'tok-one-click',
        'subscribed_at' => now(),
    ]);

    $this->post(route('newsletter.unsubscribe.one-click', ['token' => $subscriber->unsubscribe_token]))
        ->assertOk();

    expect($subscriber->fresh()->unsubscribed_at)->not->toBeNull();
});

it('връща 200 и при непознат токен, за да не отчете доставчикът счупено отписване', function () {
    $this->post(route('newsletter.unsubscribe.one-click', ['token' => 'nyama-takyv']))
        ->assertOk();
});

it('спира имейлите на потребител при POST по подписан линк', function () {
    $user = User::factory()->create(['email_opt_out_at' => null]);

    $this->post(URL::signedRoute('newsletter.user-unsubscribe', ['user' => $user->id]))
        ->assertOk();

    expect($user->fresh()->email_opt_out_at)->not->toBeNull();
});

it('отхвърля POST без валиден подпис', function () {
    $user = User::factory()->create(['email_opt_out_at' => null]);

    $this->post(route('newsletter.user-unsubscribe.one-click', ['user' => $user->id]))
        ->assertForbidden();

    expect($user->fresh()->email_opt_out_at)->toBeNull();
});
