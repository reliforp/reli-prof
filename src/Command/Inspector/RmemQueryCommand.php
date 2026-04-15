<?php

/**
 * This file is part of the reliforp/reli-prof package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Reli\Command\Inspector;

use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Format;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader as BinaryReader;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\SizeFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Query a .rmem binary file for node details and tree paths.
 */
final class RmemQueryCommand extends Command
{
    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:rmem:query')
            ->setDescription('Query a .rmem file for node details and tree paths')
            ->addArgument('file', InputArgument::REQUIRED, '.rmem file path')
            ->addOption(
                'node',
                null,
                InputOption::VALUE_REQUIRED,
                'show details and tree path for a node ID',
            )
            ->addOption(
                'address',
                null,
                InputOption::VALUE_REQUIRED,
                'find node by memory address (hex or decimal)',
            )
            ->addOption(
                'children',
                null,
                InputOption::VALUE_NONE,
                'also show direct children of the node',
            )
        ;
    }

    /**
     * @psalm-suppress MixedAssignment, MixedArrayAccess, PossiblyInvalidArrayAccess
     */
    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getArgument('file');
        if (!file_exists($file)) {
            $output->writeln("<error>File not found: {$file}</error>");
            return 1;
        }

        $reader = BinaryReader::open($file);
        $dict = $reader->getStringDict();

        /** @var string|null $node_opt */
        $node_opt = $input->getOption('node');
        /** @var string|null $addr_opt */
        $addr_opt = $input->getOption('address');
        $show_children = (bool) $input->getOption('children');

        $target_node_id = null;

        if ($node_opt !== null) {
            $target_node_id = (int) $node_opt;
        } elseif ($addr_opt !== null) {
            $addr = str_starts_with($addr_opt, '0x')
                ? (int) hexdec(substr($addr_opt, 2))
                : (int) $addr_opt;
            $target_node_id = $this->findNodeByAddress($reader, $addr);
            if ($target_node_id === null) {
                $output->writeln("<error>No node found at address 0x" . dechex($addr) . "</error>");
                return 1;
            }
        }

        if ($target_node_id === null) {
            $output->writeln('<error>Specify --node=ID or --address=ADDR</error>');
            return 1;
        }

        // Print node locations
        $this->printNodeLocations($output, $reader, $dict, $target_node_id);

        // Print node attributes
        $this->printNodeAttributes($output, $reader, $dict, $target_node_id);

        // Print tree path to root
        $output->writeln('');
        $output->writeln('<comment>Path to root:</comment>');
        $this->printPathToRoot($output, $reader, $dict, $target_node_id);

        // Print children
        if ($show_children) {
            $output->writeln('');
            $output->writeln('<comment>Children:</comment>');
            $this->printChildren($output, $reader, $dict, $target_node_id);
        }

        return 0;
    }

    /**
     * @psalm-suppress MixedAssignment, PossiblyInvalidArrayAccess
     */
    private function findNodeByAddress(BinaryReader $reader, int $address): ?int
    {
        if (!$reader->hasSection(Format::SECTION_LOCATIONS)) {
            return null;
        }
        $data = $reader->getSectionData(Format::SECTION_LOCATIONS);
        $count = $reader->getSectionElementCount(Format::SECTION_LOCATIONS);
        for ($i = 0; $i < $count; $i++) {
            $off = $i * Format::LOCATION_ROW_SIZE;
            /** @var array{1: int} */
            $addr_u = unpack('P', $data, $off + 12);
            if ($addr_u[1] === $address) {
                /** @var array{1: int} */
                $nid = unpack('V', $data, $off);
                return $nid[1];
            }
        }
        return null;
    }

    /**
     * @psalm-suppress MixedAssignment, PossiblyInvalidArrayAccess
     */
    private function printNodeLocations(
        OutputInterface $output,
        BinaryReader $reader,
        \Reli\Inspector\Output\MemoryOutput\BinaryFormat\StringDict $dict,
        int $node_id,
    ): void {
        if (!$reader->hasSection(Format::SECTION_LOCATIONS)) {
            return;
        }
        $data = $reader->getSectionData(Format::SECTION_LOCATIONS);
        $count = $reader->getSectionElementCount(Format::SECTION_LOCATIONS);
        $output->writeln("<comment>Node {$node_id} locations:</comment>");
        $found = false;
        for ($i = 0; $i < $count; $i++) {
            $off = $i * Format::LOCATION_ROW_SIZE;
            /** @var array{1: int} */
            $nid = unpack('V', $data, $off);
            if ($nid[1] !== $node_id) {
                continue;
            }
            $found = true;
            /** @var array{1: int} */
            $type_id = unpack('V', $data, $off + 4);
            /** @var array{1: int} */
            $class_id = unpack('V', $data, $off + 8);
            /** @var array{1: int} */
            $addr = unpack('P', $data, $off + 12);
            /** @var array{1: int} */
            $size = unpack('P', $data, $off + 20);
            /** @var array{1: int} */
            $str_id = unpack('V', $data, $off + 28);
            /** @var array{1: int} */
            $refcount = unpack('V', $data, $off + 32);

            $type_name = $dict->lookup($type_id[1]) ?? '?';
            $class_name = ($class_id[1] !== Format::NULL_STRING_ID)
                ? ($dict->lookup($class_id[1]) ?? '') : '';
            $str_val = ($str_id[1] !== Format::NULL_STRING_ID)
                ? ($dict->lookup($str_id[1]) ?? '') : '';

            $line = sprintf(
                '  type=%s addr=0x%x size=%s refcount=%d',
                $type_name,
                $addr[1],
                SizeFormatter::format((int) $size[1]),
                $refcount[1],
            );
            if ($class_name !== '') {
                $line .= " class={$class_name}";
            }
            if ($str_val !== '') {
                $preview = strlen($str_val) > 60
                    ? substr($str_val, 0, 57) . '...'
                    : $str_val;
                $line .= " val=\"{$preview}\"";
            }
            $output->writeln($line);
        }
        if (!$found) {
            $output->writeln('  (no locations)');
        }
    }

    /**
     * @psalm-suppress MixedAssignment, PossiblyInvalidArrayAccess
     */
    private function printNodeAttributes(
        OutputInterface $output,
        BinaryReader $reader,
        \Reli\Inspector\Output\MemoryOutput\BinaryFormat\StringDict $dict,
        int $node_id,
    ): void {
        if (!$reader->hasSection(Format::SECTION_ATTRIBUTES)) {
            return;
        }
        $data = $reader->getSectionData(Format::SECTION_ATTRIBUTES);
        $count = $reader->getSectionElementCount(Format::SECTION_ATTRIBUTES);
        $attrs = [];
        for ($i = 0; $i < $count; $i++) {
            $off = $i * 12;
            /** @var array{1: int} */
            $nid = unpack('V', $data, $off);
            if ($nid[1] !== $node_id) {
                continue;
            }
            /** @var array{1: int} */
            $key_id = unpack('V', $data, $off + 4);
            /** @var array{1: int} */
            $val_id = unpack('V', $data, $off + 8);
            $key = $dict->lookup($key_id[1]) ?? '?';
            $val = $dict->lookup($val_id[1]) ?? '?';
            $attrs[$key] = $val;
        }
        if ($attrs !== []) {
            $output->writeln('');
            $output->writeln('<comment>Attributes:</comment>');
            foreach ($attrs as $k => $v) {
                $output->writeln("  {$k} = {$v}");
            }
        }
    }

    /**
     * Build parent map from tree edges and walk to root.
     *
     * @psalm-suppress MixedAssignment, PossiblyInvalidArrayAccess
     */
    private function printPathToRoot(
        OutputInterface $output,
        BinaryReader $reader,
        \Reli\Inspector\Output\MemoryOutput\BinaryFormat\StringDict $dict,
        int $node_id,
    ): void {
        if (!$reader->hasSection(Format::SECTION_EDGES)) {
            $output->writeln('  (no edges section)');
            return;
        }

        // Build parent map: child_node_id => [parent_node_id, link_name_id]
        $data = $reader->getSectionData(Format::SECTION_EDGES);
        $count = $reader->getSectionElementCount(Format::SECTION_EDGES);
        /** @var array<int, array{int, int}> */
        $parent_map = [];
        for ($i = 0; $i < $count; $i++) {
            $off = $i * Format::EDGE_ROW_SIZE;
            $row = unpack('Vparent/Vchild/Vlid/Cis_tree/Cstrength', $data, $off);
            if ((int) $row['is_tree'] !== 0) {
                continue; // only tree edges
            }
            // Tree edge: is_tree == 0 means it IS a tree edge in the binary format
            // Actually check: what does is_tree mean?
        }

        // Re-read: in the binary format, is_tree=1 means tree edge, is_tree=0 means non-tree
        $parent_map = [];
        for ($i = 0; $i < $count; $i++) {
            $off = $i * Format::EDGE_ROW_SIZE;
            $row = unpack('Vparent/Vchild/Vlid/Cis_tree/Cstrength', $data, $off);
            if ((int) $row['is_tree'] === 1) {
                $parent_map[(int) $row['child']] = [(int) $row['parent'], (int) $row['lid']];
            }
        }

        // Load node type info for display
        $node_types = $this->loadNodeTypes($reader);

        // Walk from target to root
        $path = [];
        $current = $node_id;
        $visited = [];
        while (isset($parent_map[$current]) && !isset($visited[$current])) {
            $visited[$current] = true;
            [$parent, $lid] = $parent_map[$current];
            $link_name = $dict->lookup($lid) ?? '?';
            $type = $node_types[$current] ?? '?';
            $path[] = ['node' => $current, 'link' => $link_name, 'type' => $type];
            $current = $parent;
        }
        // Add root
        $root_type = $node_types[$current] ?? '?';
        $path[] = ['node' => $current, 'link' => '<root>', 'type' => $root_type];

        // Print bottom-up (target first, root last)
        foreach ($path as $i => $step) {
            $indent = str_repeat('  ', $i);
            $output->writeln(sprintf(
                '  %s[%s] node=%d (%s)',
                $indent,
                $step['link'],
                $step['node'],
                $step['type'],
            ));
        }
    }

    /**
     * @psalm-suppress MixedAssignment, PossiblyInvalidArrayAccess
     */
    private function printChildren(
        OutputInterface $output,
        BinaryReader $reader,
        \Reli\Inspector\Output\MemoryOutput\BinaryFormat\StringDict $dict,
        int $node_id,
    ): void {
        if (!$reader->hasSection(Format::SECTION_EDGES)) {
            return;
        }
        $data = $reader->getSectionData(Format::SECTION_EDGES);
        $count = $reader->getSectionElementCount(Format::SECTION_EDGES);
        $node_types = $this->loadNodeTypes($reader);

        $children = [];
        for ($i = 0; $i < $count; $i++) {
            $off = $i * Format::EDGE_ROW_SIZE;
            $row = unpack('Vparent/Vchild/Vlid/Cis_tree/Cstrength', $data, $off);
            if ((int) $row['parent'] === $node_id && (int) $row['is_tree'] === 1) {
                $link_name = $dict->lookup((int) $row['lid']) ?? '?';
                $child_type = $node_types[(int) $row['child']] ?? '?';
                $children[] = [
                    'child' => (int) $row['child'],
                    'link' => $link_name,
                    'type' => $child_type,
                ];
            }
        }

        if ($children === []) {
            $output->writeln('  (no children)');
            return;
        }

        foreach ($children as $c) {
            $output->writeln(sprintf(
                '  [%s] node=%d (%s)',
                $c['link'],
                $c['child'],
                $c['type'],
            ));
        }
    }

    /**
     * @return array<int, string> node_id => type_name
     * @psalm-suppress MixedAssignment, PossiblyInvalidArrayAccess
     */
    private function loadNodeTypes(BinaryReader $reader): array
    {
        if (!$reader->hasSection(Format::SECTION_NODES)) {
            return [];
        }
        $dict = $reader->getStringDict();
        $data = $reader->getSectionData(Format::SECTION_NODES);
        $count = $reader->getSectionElementCount(Format::SECTION_NODES);
        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $off = $i * Format::NODE_ROW_SIZE;
            /** @var array{1: int} */
            $nid = unpack('V', $data, $off);
            /** @var array{1: int} */
            $tid = unpack('V', $data, $off + 8); // type_id at offset 8
            $result[$nid[1]] = $dict->lookup($tid[1]) ?? '?';
        }
        return $result;
    }
}
