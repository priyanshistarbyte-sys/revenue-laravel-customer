<?php

namespace Tests\Unit;

use App\Http\Controllers\LinksController;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class LinksControllerTest extends TestCase
{
    public function test_resolve_adx_id_prefers_the_visible_picker_and_falls_back_to_the_new_domain_picker(): void
    {
        $controller = new LinksController();

        $request = Request::create('/links', 'POST', ['adx_id' => '5', 'new_domain_adx_id' => '9']);
        $method = new \ReflectionMethod($controller, 'resolveAdxId');
        $method->setAccessible(true);

        $this->assertSame(5, $method->invoke($controller, $request));

        $request = Request::create('/links', 'POST', ['new_domain_adx_id' => '9']);
        $this->assertSame(9, $method->invoke($controller, $request));

        $request = Request::create('/links', 'POST', []);
        $this->assertNull($method->invoke($controller, $request));
    }
}
