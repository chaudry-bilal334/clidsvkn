<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a
| specific PHPUnit test case class. By default, that class is
| "PHPUnit\Framework\TestCase". Of course, you may need to change it using
| the "pest()->extend()" helper to bind a different class or polyfills.
|
| If you are binding to "Tests\TestCase", then you may call the
| "refreshApplication()" helper to refresh the application state between tests.
|
*/

use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');
