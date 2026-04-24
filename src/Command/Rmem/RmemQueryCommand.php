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

namespace Reli\Command\Rmem;

use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Format;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader as BinaryReader;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\SizeFormatter;
use Reli\Command\DockerProfile;
use Reli\Command\ReliCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Query a .rmem binary file for node details and tree paths.
 */
final class RmemQueryCommand extends ReliCommand
{
    #[\Override]
    public static function getDockerProfile(): DockerProfile
    {
        return DockerProfile::Minimal;
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('rmem:query')
            ->setDescription('Query a .rmem file for node details and tree paths')
            ->addArgument('file', InputArgument::OPTIONAL, '.rmem file path (not needed with --server)')
            ->addOption(
                'server',
                null,
                InputOption::VALUE_REQUIRED,
                'query a running rmem:serve instance via Unix socket path',
            )
            ->addOption(
                'action',
                null,
                InputOption::VALUE_REQUIRED,
                'raw JSON action to send to server (with --server)',
            )
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
            ->addOption(
                'sections',
                null,
                InputOption::VALUE_NONE,
                'list all sections in the rmem file with sizes and element counts',
            )
            ->addOption(
                'check-integrity',
                null,
                InputOption::VALUE_NONE,
                'validate string_dict and other sections for truncation or corruption',
            )
        ;
    }

    /**
     * @psalm-suppress MixedAssignment, MixedArrayAccess, PossiblyInvalidArrayAccess
     */
    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string|null $serverPath */
        $serverPath = $input->getOption('server');
        if ($serverPath !== null) {
            return $this->executeViaServer($serverPath, $input, $output);
        }

        $file = (string) $input->getArgument('file');
        if ($file === '' || !file_exists($file)) {
            $output->writeln("<error>File not found: {$file}</error>");
            return 1;
        }

        $reader = BinaryReader::open($file);

        if ((bool) $input->getOption('sections')) {
            $this->printSections($output, $reader);
            return 0;
        }

        if ((bool) $input->getOption('check-integrity')) {
            return $this->checkIntegrity($output, $reader);
        }

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
    private function printSections(OutputInterface $output, BinaryReader $reader): void
    {
        $names = $reader->getSectionNames();
        $output->writeln(sprintf('<comment>%d sections:</comment>', count($names)));
        $output->writeln(sprintf(
            '  %-20s %12s %12s %12s',
            'Name',
            'Elements',
            'Length',
            'Offset',
        ));
        $output->writeln('  ' . str_repeat('-', 58));
        foreach ($names as $name) {
            $output->writeln(sprintf(
                '  %-20s %12s %12s %12s',
                $name,
                number_format($reader->getSectionElementCount($name)),
                SizeFormatter::format($reader->getSectionLength($name)),
                '0x' . dechex($reader->getSectionOffset($name)),
            ));
        }
    }

    private function checkIntegrity(OutputInterface $output, BinaryReader $reader): int
    {
        $errors = 0;

        // Check string_dict
        if ($reader->hasSection('string_dict')) {
            $output->writeln('<comment>Checking string_dict...</comment>');
            $data = $reader->getSectionData('string_dict');
            $dataLen = strlen($data);
            $output->writeln("  section_bytes: {$dataLen}");

            if ($dataLen < 4) {
                $output->writeln('  <error>Too short for header</error>');
                $errors++;
            } else {
                $count = unpack('V', $data, 0)[1];
                $output->writeln("  declared_count: {$count}");

                $offset = 4;
                $readable = 0;
                $maxLen = 0;
                $badLen = false;
                for ($i = 0; $i < $count; $i++) {
                    if ($offset + 4 > $dataLen) {
                        $output->writeln("  <error>TRUNCATED at string #{$i}: need len header at offset {$offset}, section ends at {$dataLen}</error>");
                        $errors++;
                        break;
                    }
                    $len = unpack('V', $data, $offset)[1];
                    $offset += 4;
                    if ($len > 100_000_000) {
                        $output->writeln("  <error>BAD_LEN at string #{$i}: len={$len} at offset " . ($offset - 4) . "</error>");
                        $errors++;
                        $badLen = true;
                        break;
                    }
                    if ($offset + $len > $dataLen) {
                        $output->writeln("  <error>TRUNCATED at string #{$i}: need {$len} bytes at offset {$offset}, only " . ($dataLen - $offset) . " available</error>");
                        $errors++;
                        break;
                    }
                    if ($len > $maxLen) {
                        $maxLen = $len;
                    }
                    $offset += $len;
                    $readable++;
                }

                if ($readable === $count && !$badLen) {
                    $output->writeln("  <info>OK: all {$count} strings readable ({$offset}/{$dataLen} bytes consumed)</info>");
                    if ($offset < $dataLen) {
                        $output->writeln("  warning: " . ($dataLen - $offset) . " trailing bytes after last string");
                    }
                } else {
                    $output->writeln("  readable: {$readable}/{$count}");
                }
                $output->writeln("  max_string_len: {$maxLen}");
            }
        } else {
            $output->writeln('<comment>No string_dict section</comment>');
        }

        // Check node/edge/location counts vs section sizes
        foreach (
            [
            [Format::SECTION_NODES, Format::NODE_ROW_SIZE, 'nodes'],
            [Format::SECTION_EDGES, Format::EDGE_ROW_SIZE, 'edges'],
            [Format::SECTION_LOCATIONS, Format::LOCATION_ROW_SIZE, 'locations'],
            ] as [$section, $rowSize, $label]
        ) {
            if (!$reader->hasSection($section)) {
                continue;
            }
            $elemCount = $reader->getSectionElementCount($section);
            $sectionLen = $reader->getSectionLength($section);
            $expected = $elemCount * $rowSize;
            $status = $sectionLen >= $expected ? '<info>OK</info>' : '<error>SHORT</error>';
            $output->writeln("{$status} {$label}: {$elemCount} elements × {$rowSize} = {$expected} bytes, section has {$sectionLen}");
            if ($sectionLen < $expected) {
                $errors++;
            }
        }

        // Check node_sizes / node_classes
        foreach (['node_sizes' => 8, 'node_classes' => 4] as $section => $elemSize) {
            if (!$reader->hasSection($section)) {
                continue;
            }
            $elemCount = $reader->getSectionElementCount($section);
            $sectionLen = $reader->getSectionLength($section);
            $expected = $elemCount * $elemSize;
            $status = $sectionLen >= $expected ? '<info>OK</info>' : '<error>SHORT</error>';
            $output->writeln("{$status} {$section}: {$elemCount} slots × {$elemSize} = {$expected} bytes, section has {$sectionLen}");
            if ($sectionLen < $expected) {
                $errors++;
            }
        }

        $output->writeln('');
        if ($errors > 0) {
            $output->writeln("<error>{$errors} integrity error(s) found</error>");
            return 1;
        }
        $output->writeln('<info>All checks passed</info>');
        return 0;
    }

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

    private function executeViaServer(
        string $socketPath,
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $sock = @stream_socket_client("unix://{$socketPath}", $errno, $errstr, 5);
        if ($sock === false) {
            $output->writeln("<error>Cannot connect to {$socketPath}: {$errstr}</error>");
            return 1;
        }

        /** @var string|null $rawAction */
        $rawAction = $input->getOption('action');
        if ($rawAction !== null) {
            // Raw JSON mode
            fwrite($sock, $rawAction . "\n");
            $response = fgets($sock);
            fclose($sock);
            if ($response === false) {
                $output->writeln('<error>No response from server</error>');
                return 1;
            }
            $output->writeln($response);
            return 0;
        }

        // Build action from CLI options
        /** @var string|null $nodeOpt */
        $nodeOpt = $input->getOption('node');
        /** @var string|null $addrOpt */
        $addrOpt = $input->getOption('address');
        $showChildren = (bool) $input->getOption('children');

        /** @var list<string> $requests */
        $requests = [];

        if ($addrOpt !== null) {
            $requests[] = (string)json_encode(['action' => 'query.find_by_address', 'address' => $addrOpt]);
        }

        if ($nodeOpt !== null) {
            $nodeId = (int) $nodeOpt;
            $requests[] = (string)json_encode(['action' => 'query.node_detail', 'node_id' => $nodeId]);
            $requests[] = (string)json_encode(['action' => 'query.path_to_root', 'node_id' => $nodeId]);
            if ($showChildren) {
                $requests[] = (string)json_encode(['action' => 'query.children', 'node_id' => $nodeId]);
            }
        }

        if ($requests === []) {
            // Default: hello
            $requests[] = (string)json_encode(['action' => 'server.hello']);
        }

        foreach ($requests as $req) {
            fwrite($sock, $req . "\n");
            $response = fgets($sock);
            if ($response === false) {
                break;
            }
            $decoded = json_decode($response, true);
            $output->writeln((string)json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $output->writeln('');
        }

        fclose($sock);
        return 0;
    }
}
