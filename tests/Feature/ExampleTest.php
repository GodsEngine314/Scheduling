<?php

// The stock example asserted a welcome page at '/'. That route is now a
// redirect into the console, so the real coverage lives in BoardPageTest.
test('the root redirects into the schedule console', function () {
    $this->get('/')->assertRedirect('/board');
});
