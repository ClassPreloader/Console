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

use ClassPreloader\Console\PreCompileCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class CommandTest extends TestCase
{
    private $autoloadFunc;

    /**
     * This additional autoloader is needed to test if the class is not put multiple times into the cache.
     *
     * @before
     */
    public function setUpAutoloader()
    {
        $this->autoloadFunc = function ($class) {
            return false;
        };

        spl_autoload_register($this->autoloadFunc, true, true);
    }

    /**
     * @after
     */
    public function tearDownAutoloader()
    {
        spl_autoload_unregister($this->autoloadFunc);
    }

    public function commandProvider()
    {
        $out = __DIR__.DIRECTORY_SEPARATOR.'output.txt';
        $bar = $file = __DIR__.DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR.'Bar.php';
        $foo = $file = __DIR__.DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR.'Foo.php';
        $dir = str_replace('\\', '\\\\', __DIR__.DIRECTORY_SEPARATOR.'stubs');
        $file = str_replace('\\', '\\\\', __DIR__.DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR.'Foo.php');

        $expected = <<<EOT
> Loading configuration file
- Found 2 files
> Compiling classes
- Writing $bar
- Writing $foo
> Compiled loader written to $out
- Files: 2/2 (skipped: 0)
- Filesize: 0 kb
EOT;
        $expectedSkip = <<<EOT
> Loading configuration file
- Found 2 files
> Compiling classes
- Skipping $bar
- Skipping $foo
> Compiled loader written to $out
- Files: 0/2 (skipped: 2)
- Filesize: 0 kb
EOT;

        $first = <<<EOT
<?php
namespace {
use Tests\Stubs\Baz;
class Bar
{
    public function qwerty()
    {
        \$foo = new Baz();
        // this comment should be removed
        return '$dir';
    }
}
}

namespace {
class Foo extends Bar
{
    public function baz()
    {
        return '$file';
    }
}
}
EOT;

        $second = <<<EOT
<?php
namespace {
use Tests\Stubs\Baz;
class Bar
{
    public function qwerty()
    {
        \$foo = new Baz();
        return '$dir';
    }
}
}

namespace {
class Foo extends Bar
{
    public function baz()
    {
        return '$file';
    }
}
}
EOT;

        $third = <<<EOT
<?php
namespace {
use Tests\Stubs\Baz;
class Bar
{
    public function qwerty()
    {
        \$foo = new Baz();
        // this comment should be removed
        return __DIR__;
    }
}
}

namespace {
class Foo extends Bar
{
    public function baz()
    {
        return '$file';
    }
}
}
EOT;

        $last = <<<EOT
<?php
namespace {
use Tests\Stubs\Baz;
class Bar
{
    public function qwerty()
    {
        \$foo = new Baz();
        // this comment should be removed
        return '$dir';
    }
}
}

namespace {
class Foo extends Bar
{
    public function baz()
    {
        return __FILE__;
    }
}
}
EOT;

        return [
            [
                [
                    '--config' => __DIR__.DIRECTORY_SEPARATOR.'classlist.php',
                    '--output' => $out,
                ],
                $expected,
                $first,
            ],
            [
                [
                    '--config'         => __DIR__.DIRECTORY_SEPARATOR.'classlist.php',
                    '--output'         => $out,
                    '--strip_comments' => true,
                ],
                $expected,
                $second,
            ],
            [
                [
                    '--config'  => __DIR__.DIRECTORY_SEPARATOR.'classlist.php',
                    '--output'  => $out,
                    '--fix_dir' => false,
                ],
                $expected,
                $third,
            ],
            [
                [
                    '--config'   => __DIR__.DIRECTORY_SEPARATOR.'classlist.php',
                    '--output'   => $out,
                    '--fix_file' => false,
                ],
                $expected,
                $last,
            ],
            [
                [
                    '--config'        => __DIR__.DIRECTORY_SEPARATOR.'classlist.php',
                    '--output'        => $out,
                    '--skip_dir_file' => true,
                ],
                $expectedSkip,
                '<?php',
            ],
        ];
    }

    /**
     * @dataProvider commandProvider
     */
    public function testCommandBasic(array $config, $expected, $compiled)
    {
        $command = new PreCompileCommand();
        $input = new ArrayInput($config);
        $output = new BufferedOutput();

        $this->assertSame(0, $command->run($input, $output));
        $this->assertSame($this->normalize($expected), $this->normalize($output->fetch()));

        $contents = file_get_contents(__DIR__.DIRECTORY_SEPARATOR.'output.txt');

        $this->assertSame($this->normalize($compiled), $this->normalize($contents));

        unlink(__DIR__.DIRECTORY_SEPARATOR.'output.txt');
    }

    /**
     * @dataProvider commandProvider
     */
    public function testStrictCommand(array $config, $expected, $compiled)
    {
        if (version_compare(PHP_VERSION, '7') < 1) {
            $this->setExpectedException('RuntimeException', 'Strict mode requires PHP 7 or greater.');
        }

        $command = new PreCompileCommand();
        $input = new ArrayInput(array_merge($config, ['--strict_types' => true]));
        $output = new BufferedOutput();

        $this->assertSame(0, $command->run($input, $output));
        $this->assertSame($this->normalize($expected), $this->normalize($output->fetch()));

        $contents = file_get_contents(__DIR__.DIRECTORY_SEPARATOR.'output.txt');

        $this->assertSame($this->normalize(str_replace('<?php', '<?php declare(strict_types=1);', $compiled)), $this->normalize($contents));

        unlink(__DIR__.DIRECTORY_SEPARATOR.'output.txt');
    }

    public function testIncludeCompiledFile()
    {
        $dir = __DIR__.DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR;

        $classes = [
            $dir.'Bar.php',
            $dir.'Foo.php',
            $dir.'Baz.php',
        ];

        $config = [
            '--config' => implode(',', $classes),
            '--output' => __DIR__.DIRECTORY_SEPARATOR.'compiled.php',
        ];

        $command = new PreCompileCommand();
        $input = new ArrayInput($config);
        $output = new BufferedOutput();

        $command->run($input, $output);

        $compiled = __DIR__.DIRECTORY_SEPARATOR.'compiled.php';

        register_shutdown_function(function () use ($compiled) {
            unlink($compiled);
        });

        require $compiled;

        $this->assertTrue(class_exists('Tests\Stubs\Baz'));
        $this->assertTrue(class_exists('Foo'));
        $this->assertTrue(class_exists('Bar'));
    }

    protected function normalize($string)
    {
        $string = str_replace(["\r\n", "\r"], "\n", $string);
        $string = preg_replace("/\n{2,}/", "\n\n", $string);

        return rtrim($string);
    }
}
