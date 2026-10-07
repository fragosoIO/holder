<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Inherited Methods
 * @method void wantTo($text)
 * @method void wantToTest($text)
 * @method void execute($callable)
 * @method void expectTo($prediction)
 * @method void expect($prediction)
 * @method void amGoingTo($argumentation)
 * @method void am($role)
 * @method void lookForwardTo($achieveValue)
 * @method void comment($description)
 * @method void pause($vars = [])
 *
 * @SuppressWarnings(PHPMD)
*/
class ApiTester extends \Codeception\Actor
{
    use _generated\ApiTesterActions;

    /**
     * The generated actions do not expose PhpBrowser's cookie jar.
     */
    public function resetCookie(string $name): void
    {
        $metadata = new \ReflectionProperty(\Codeception\Scenario::class, 'metadata');
        /** @var \Codeception\Test\Metadata $meta */
        $meta = $metadata->getValue($this->getScenario());
        /** @var \Codeception\Lib\ModuleContainer $modules */
        $modules = $meta->getService('modules');
        /** @var \Codeception\Module\PhpBrowser $browser */
        $browser = $modules->getModule('PhpBrowser');
        $browser->resetCookie($name);
    }
}
