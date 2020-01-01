<?php

declare(strict_types=1);

/*
 * This file is part of Class Preloader.
 *
 * (c) Graham Campbell <graham@alt-three.com>
 * (c) Michael Dowling <mtdowling@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ClassPreloader\Console;

use Symfony\Component\Console\Application as BaseApplication;

/**
 * This is the application class.
 *
 * This is sets everything up for the CLI.
 */
final class Application extends BaseApplication
{
    /**
     * Create a new application instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct('Class Preloader', '3.0');

        $this->add(new PreCompileCommand());
    }
}
