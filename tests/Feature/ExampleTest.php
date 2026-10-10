<?php

declare(strict_types=1);

/** covers §4.2, §4.10 */
it('sends guests from the root url via the dashboard to the login page', function (): void {
    $this->followingRedirects()
        ->get('/')
        ->assertOk()
        ->assertSee(route('login.store'), escape: false);

    $this->get('/')->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertRedirect(route('login'));
});
