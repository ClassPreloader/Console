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

use ClassPreloader\CodeGenerator;
use ClassPreloader\Exception\VisitorExceptionInterface;
use ClassPreloader\OutputWriter;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This is the pre-compile command class.
 *
 * This allows the user to communicate with class preloader.
 */
final class PreCompileCommand extends Command
{
    /**
     * Configure the current command.
     *
     * @return void
     */
    protected function configure()
    {
        parent::configure();

        $this->setName('compile')
            ->setDescription('Compiles classes into a single file')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'CSV of filenames to load, or the path to a PHP script that returns an array of file names')
            ->addOption('output', null, InputOption::VALUE_REQUIRED)
            ->addOption('skip_dir_file', null, InputOption::VALUE_NONE, 'Skip files with __DIR__ or __FILE__ to make the cache portable')
            ->addOption('fix_dir', null, InputOption::VALUE_REQUIRED, 'Convert __DIR__ constants to the original directory of a file', 1)
            ->addOption('fix_file', null, InputOption::VALUE_REQUIRED, 'Convert __FILE__ constants to the original path of a file', 1)
            ->addOption('strict_types', null, InputOption::VALUE_REQUIRED, 'Set to 1 to enable strict types mode', 0)
            ->addOption('strip_comments', null, InputOption::VALUE_REQUIRED, 'Set to 1 to strip comments from each source file', 0)
            ->setHelp(
                <<<'EOF'
The <info>%command.name%</info> command iterates over each script, normalizes
the file to be wrapped in namespaces, and combines each file into a single PHP
file.
EOF
            );
    }

    /**
     * Executes the pre-compile command.
     *
     * @param \Symfony\Component\Console\Input\InputInterface   $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        self::validateCommand($input);

        $output->writeln('> Loading configuration file');
        $config = (string) $input->getOption('config');
        $files = ConfigResolver::getFileList($config);
        $output->writeln('- Found '.count($files).' files');

        $options = $this->getOptions($input);
        $codeGen = CodeGenerator::create($options);
        $outputFile = (string) $input->getOption('output');
        $comments = (bool) $input->getOption('strip_comments');

        self::compileFiles($outputFile, $options['strict'], (function () use ($codeGen, $comments, $files, $output, $outputFile) {
            $output->writeln('> Compiling classes');

            $count = 0;
            $countSkipped = 0;

            foreach ($files as $file) {
                $count++;

                try {
                    $code = $codeGen->getCode($file, !$comments);
                    $output->writeln('- Writing '.$file);
                    yield $code;
                } catch (VisitorExceptionInterface $e) {
                    $countSkipped++;
                    $output->writeln('- Skipping '.$file);
                }
            }

            $output->writeln("> Compiled loader written to $outputFile");
            $output->writeln('- Files: '.($count - $countSkipped).'/'.$count.' (skipped: '.$countSkipped.')');
        })());

        $output->writeln('- Filesize: '.(round(filesize($outputFile) / 1024)).' kb');

        return 0;
    }

    /**
     * Validate the command options.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     *
     * @throws \InvalidArgumentException
     *
     * @return void
     */
    private static function validateCommand(InputInterface $input)
    {
        if (!$input->getOption('output')) {
            throw new InvalidArgumentException('An output option is required.');
        }

        if (!$input->getOption('config')) {
            throw new InvalidArgumentException('A config option is required.');
        }
    }

    /**
     * Compile the given files, leaving the result in the output file.
     *
     * @param string           $outputFile
     * @param bool             $strictTypes
     * @param iterable<string> $files
     *
     * @throws \ClassPreloader\Exception\IOException
     *
     * @return void
     */
    private static function compileFiles(string $outputFile, bool $strictTypes, $files)
    {
        $handle = OutputWriter::openOutputFile($outputFile);

        try {
            OutputWriter::writeOpeningTag($handle, $strictTypes);

            foreach ($files as $code) {
                OutputWriter::writeFileContent($handle, $code."\n");
            }
        } finally {
            OutputWriter::closeHandle($handle);
        }
    }

    /**
     * Get the options to pass to the factory.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     *
     * @return bool[]
     */
    protected function getOptions(InputInterface $input)
    {
        return [
            'dir'    => (bool) $input->getOption('fix_dir'),
            'file'   => (bool) $input->getOption('fix_file'),
            'skip'   => (bool) $input->getOption('skip_dir_file'),
            'strict' => (bool) $input->getOption('strict_types'),
        ];
    }
}
