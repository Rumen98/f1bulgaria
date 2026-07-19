<?php

declare(strict_types=1);

it('рендерира валиден JSON-LD без изтекъл Blade код', function () {
    $response = $this->get('/');

    $response->assertOk()
        // Ключът "@context" трябва да оцелее непокътнат — Blade има @context
        // директива, която веднъж го изяде тихо в production.
        ->assertSee('"@context":"https://schema.org"', escape: false)
        ->assertDontSee('$__contextArgs', escape: false)
        ->assertDontSee('<?php', escape: false);

    // Схемата е парсваем JSON с очакваната структура.
    preg_match('/<script type="application\/ld\+json">(.+?)<\/script>/s', $response->getContent(), $m);
    expect($m)->toHaveCount(2);

    $schema = json_decode($m[1], true);
    expect($schema)->not->toBeNull()
        ->and($schema['@context'])->toBe('https://schema.org')
        ->and($schema['@graph'][0]['@type'])->toBe('Organization')
        ->and($schema['@graph'][1]['@type'])->toBe('WebSite');
});
