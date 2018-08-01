<?php

namespace Awssat\BladeAudit\Commands;


use Awssat\BladeAudit\Analyze;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;

class BladeAudit extends Command
{
    protected $signature = 'blade:audit {view : view name}';

    protected $description = 'Extensive information of a blade view';

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $view = $this->argument('view');

        $result = Analyze::view($view);

        if (! $result) {
            $this->error('view ['.$view.'] not found!');

            return;
        }

        $this->viewInfoTable($result->getViewInfo());
        $this->output->newLine();

        $this->directivesInfoTable($result->getDirectivesInfo());
        $this->output->newLine();

        $this->nestingTable($result->getNestedLevels());
        $this->output->newLine();

        $this->warningsTable($result->getWarnings());
    }

    protected function viewInfoTable(Collection $viewInfo)
    {
        return (new Table($this->output))
            ->setHeaders([
                [new TableCell('View Information', ['colspan' => 2])],
            ])
            ->setRows(
                    $viewInfo->toArray()
                )
            ->render();
    }

    protected function directivesInfoTable(Collection $directivesInfo)
    {
        return (new Table($this->output))
            ->setHeaders([
                [new TableCell('Directives Information', ['colspan' => 3])],
                ['Directive', 'Repetition', 'Type']
            ])
            ->setRows(
                    $directivesInfo->map(function ($item) {
                        $item[2] = '<fg='.($item[2] != 'custom' ? 'blue' : 'yellow').'>'.$item[2].'</>';

                        return $item;
                    })->toArray()
                )
            ->render();
    }

    protected function nestingTable(Collection $nestedLevels)
    {
        $lastLevel = $nestedLevels->max(1);

        (new Table($this->output))
            ->setHeaders([
                [new TableCell('Directives Nesting Levels', ['colspan' => $lastLevel + 1])],
                range(1, $lastLevel + 1),
            ])
            ->setRows(
                    $nestedLevels->map(function ($item) {
                        $level = $item[1];
                        $items = array_pad([], $level, ' |---');
                        $items[$level] = '<fg=blue>'.$item[0].'</>';

                        return $items;
                    })->toArray()
                )
        ->render();
    }

    protected function warningsTable(Collection $warnings)
    {
        if ($warnings->isEmpty()) {
            return;
        }

        return (new Table($this->output))
            ->setHeaders([
                [new TableCell('Audit Notes', ['colspan' => 2])],
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
