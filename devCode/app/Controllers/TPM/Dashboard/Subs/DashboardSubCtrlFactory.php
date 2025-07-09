<?php
/**
 * Factory Class for Dashboard Widgets
 *
 * @keywords dashboard, widget
 */

namespace Controllers\TPM\Dashboard\Subs;

/**
 * Class DashboardSubCtrlFactory
 *
 * @package Controllers\TPM\Dashboard\Subs
 */
#[\AllowDynamicProperties]
class DashboardSubCtrlFactory
{
    /**
     * directory separator
     */
    public const DS = "\\";

    /**
     * @var string Require Interface
     */
    private $dashInterface = \Controllers\TPM\Dashboard\Subs\DashInterface::class;

    /**
     * @var Object contains the class instance
     */
    private $builtClass;

    /**
     * DashboardSubCtrlFactory constructor.
     *
     * @param string $class    class name for widget controller
     * @param int    $tenantID current tenant id
     * @throws \RuntimeException If the needed class does not exist.

     */
    public function __construct($class, $tenantID)
    {
        \Xtra::requireInt($tenantID);
        $className = __NAMESPACE__ . self::DS  . $class;

        if (! class_exists($className)) {
            throw new \RuntimeException("class, $className, does not exist.");
        }

        $this->builtClass = new $className($tenantID);

        // verify that the $className implements the $dashInterface.
        $interfaceCheck = class_implements($this->builtClass);
        foreach ($interfaceCheck as $interface) {
            if ($interface !== $this->dashInterface) {
                throw new \RuntimeException("class $className must implement $this->dashInterface");
            }
        }
    }

    /**
     * return $this->builtClass
     *
     * @return mixed
     */
    public function getBuiltClass()
    {
        return $this->builtClass;
    }
}
