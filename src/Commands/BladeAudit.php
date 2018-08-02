<?php

namespace Awssat\BladeAudit\Commands;


use Illuminate\Support\Str;
use Awssat\BladeAudit\Analyze;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;

class BladeAudit extends Command
{
    protected $signature = 'blade:audit {view? : view name}';

    protected $description = 'Extensive information of a blade view';

    /** @var \Illuminate\Filesystem\Filesystem */
    protected $filesystem;

    protected $allViewsResult;

    public function __construct(Filesystem $filesystem)
    {
        parent::__construct();

        $this->filesystem = $filesystem;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $view = $this->argument('view');

        $allViews = empty($view);

        if ($allViews) {
            $views = $this->getAllViews();
            $this->allViewsResult = Collection::make();
        } else {
            $views = Collection::wrap($view);
        }

        $views->each(function ($view) use ($allViews) {
            $result = Analyze::view($view);

            if (! $result) {
                $this->error('view ['.$view.'] not found!');

                return;
            }

            if (! $allViews) {
                $this->outputOneView($result);
            } else {
                $this->allViewsResult->push([$result, $view]);
            }
        });

        if ($allViews) {
            $this->outputAllViews();
        }
    }

    protected function outputOneView($result)
    {
        (new Table($this->output))
            ->setHeaders([
                    [new TableCell('View Information', ['colspan' => 2])],
            ])
            ->setRows($result->getViewInfo()->toArray())
            ->render();

        $this->output->newLine();

        (new Table($this->output))
            ->setHeaders([
                [new TableCell('Directives Information', ['colspan' => 3])],
                ['Directive', 'Repetition', 'Type']
            ])
            ->setRows(
                    $result->getDirectivesInfo()->map(function ($item) {
                        $item[2] = '<fg='.($item[2] != 'custom' ? 'blue' : 'yellow').'>'.$item[2].'</>';

                        return $item;
                    })->toArray()
                )
            ->render();

        $this->output->newLine();

        $lastLevel = $result->getNestedLevels()->max(1);

        (new Table($this->output))
            ->setHeaders([
                [new TableCell('Directives Nesting Levels', ['colspan' => $lastLevel + 1])],
                range(1, $lastLevel + 1),
            ])
            ->setRows(
                    $result->getNestedLevels()->map(function ($item) {
                        $level = $item[1];
                        $items = array_pad([], $level, ' |---');
                        $items[$level] = '<fg=blue>'.$item[0].'</>';

                        return $items;
                    })->toArray()
                )
        ->render();

        if ($result->getWarnings()->isNotEmpty()) {
            $this->output->newLine();

            (new Table($this->output))
            ->setHeaders([
                [new TableCell('Audit Notes', ['colspan' => 2])],
            ])
            ->setStyle('compact')
            ->setRows(
                    $result->getWarnings()->map(function ($item) {
                        $item[0] = '<fg=yellow>'.$item[0].':</>';

                        return $item;
                    })->toArray()
                )
            ->render();
        }
    }

    protected function outputAllViews()
    {
        $result = $this->allViewsResult->reduce(function ($carry, $item) {
            [$result, $view] = $item;

            if (empty($carry)) {
                $carry = ['info' => [], 'directives' => [], 'warnings' => []];
            }

            $carry['info'] = $result->getViewInfo()->mapWithKeys(function ($item) use ($carry) {
                return [$item[0] => isset($carry['info'][$item[0]])
                                    ? $carry['info'][$item[0]] + $item[1] : $item[1]
                                ];
            });

            $carry['directives'] = $result->getDirectivesInfo()->mapWithKeys(function ($item) use ($carry) {
                $item[1] = ! empty($carry['directives'][$item[0]])
                            ? $carry['directives'][$item[0]][1] + $item[1]
                            : $item[1];

                return [$item[0] => $item];
            });

            $carry['warnings'][$view] = $result->getWarnings();

            return $carry;
        });

        (new Table($this->output))
            ->setHeaders([
                    [new TableCell('All Views Information', ['colspan' => 2])],
            ])
            ->setRows(
                $result['info']
                        ->map(function ($v, $k) {
                            return [$k, $v];
                        })
                        ->filter(function ($v, $k) {
                            return $k != 'Longest Line (chars)';
                        })
                        ->values()
                        ->toArray()
            )
            ->render();

        $this->output->newLine();

        (new Table($this->output))
            ->setHeaders([
                [new TableCell('Directives Information', ['colspan' => 3])],
                ['Directive', 'Repetition', 'Type']
            ])
            ->setRows(
                    $result['directives']->map(function ($item) {
                        $item[2] = '<fg='.($item[2] != 'custom' ? 'blue' : 'yellow').'>'.$item[2].'</>';

                        return $item;
                    })->toArray()
                )
            ->render();

        foreach ($result['warnings'] as $view => $warnings) {
            if ($warnings->isEmpty()) {
                continue;
            }

            $this->output->newLine();

            (new Table($this->output))
                ->setHeaders([
                    [new TableCell('Audit Notes: <fg=cyan>('.$view.')</>', ['colspan' => 2])],
                ])
                ->setStyle('compact')
                ->setRows(
                        $warnings->map(function ($item) {
                            $item[0] = '<fg=yellow>'.$item[0].':</>';

                            return $item;
                        })->toArray()
                    )
                ->render();
        }
    }

    /**
     * @return Illuminate\Support\Collection
     */
    protected function getAllViews()
    {
        return Collection::wrap(
                $this->filesystem->allFiles(resource_path('views'))
            )->map(function ($file) {
                return str_replace(
                            ['/', '.blade.php'],
                    ['.', ''],
                            Str::after($file, resource_path('views').'/')
                );
            });
    }
}
