<?php

namespace LibreNMS\Tests\Feature;

use App\Facades\DeviceCache;
use App\Facades\LibrenmsConfig;
use App\Http\Controllers\Device\Tabs\OverviewController;
use App\Http\Controllers\Table\SensorsController;
use App\Models\Device;
use App\Models\Sensor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;
use JsonSchema\Validator;
use LibreNMS\Enum\SensorType;
use LibreNMS\Tests\DBTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;

class SensorCounterDisplayTest extends DBTestCase
{
    use DatabaseTransactions;

    /** @param array<string, mixed> $attributes */
    private function counter(array $attributes = [], string $os = 'counter-example'): Sensor
    {
        $sensor = (new Sensor)->forceFill(array_merge([
            'sensor_id' => 999999, 'device_id' => 1, 'sensor_index' => 'calls.0',
            'sensor_type' => 'example-counter', 'sensor_class' => 'count',
            'sensor_descr' => 'Total Calls', 'rrd_type' => 'COUNTER', 'group' => 'Calls',
            'sensor_current' => 185145, 'sensor_prev' => 185144, 'category' => null,
        ], $attributes));
        $sensor->setRelation('device', (new Device)->forceFill(['os' => $os]));

        return $sensor;
    }

    /** @param list<array<string, string>> $rules */
    private function rules(array $rules, string $os = 'counter-example'): void
    {
        LibrenmsConfig::set("os.$os.sensor_counter_display", $rules);
    }

    public function testUnconfiguredAndExplicitRateCountersKeepExistingBehaviour(): void
    {
        $sensor = $this->counter();
        $this->assertFalse($sensor->displaysCounterTotal());
        $this->assertSame('3.33 m ', $sensor->formatValue());
        $this->rules([['type' => 'example-counter', 'display' => 'rate']]);
        $this->assertFalse($sensor->displaysCounterTotal());
        $this->assertSame('3.33 m ', $sensor->formatValue());
    }

    #[DataProvider('counterTypes')]
    public function testOptInTotalsWorkAcrossCounterTypesWithoutVendorChecks(string $rrdType): void
    {
        $this->rules([['type' => 'example-counter', 'display' => 'total']]);
        $sensor = $this->counter(['rrd_type' => $rrdType]);
        $this->app->setLocale('en');
        $this->assertTrue($sensor->displaysCounterTotal());
        $this->assertSame('185,145', $sensor->formatValue());
        $sensor->sensor_prev = 999999;
        $this->assertSame('185,145', $sensor->formatValue());
        $this->app->setLocale('de');
        $this->assertSame(extension_loaded('intl') ? '185.145' : '185,145', $sensor->formatValue());
        $sensor->sensor_limit = 250000;
        $this->assertSame(extension_loaded('intl') ? '250.000' : '250,000', $sensor->formatValue('sensor_limit'));
        $sensor->sensor_current = 0;
        $this->assertSame('0', $sensor->formatValue());
        $sensor->sensor_current = null;
        $this->assertSame('NaN', $sensor->formatValue());
        $this->assertSame('-', $sensor->formatValue('sensor_limit_low'));
    }

    /** @return list<array{string}> */
    public static function counterTypes(): array
    {
        return [['COUNTER'], ['DERIVE'], ['DCOUNTER'], ['DDERIVE']];
    }

    public function testRulesMatchTypeAndOptionalIndexWithFirstMatchPrecedence(): void
    {
        $this->rules([
            ['type' => 'example-counter', 'index' => 'excluded.0', 'display' => 'rate'],
            ['type' => 'example-counter', 'display' => 'total'],
        ]);
        $this->assertFalse($this->counter(['sensor_index' => 'excluded.0'])->displaysCounterTotal());
        $this->assertTrue($this->counter()->displaysCounterTotal());
        $this->assertFalse($this->counter(['sensor_type' => 'other-counter'])->displaysCounterTotal());
        $this->rules([['type' => 'example-counter', 'index' => 'calls.0', 'display' => 'total']]);
        $this->assertTrue($this->counter()->displaysCounterTotal());
        $this->assertFalse($this->counter(['sensor_index' => 'calls.1'])->displaysCounterTotal());
        $this->assertFalse($this->counter([], 'unconfigured-os')->displaysCounterTotal());
        $this->rules([]);
        $this->assertFalse($this->counter()->displaysCounterTotal());
    }

    public function testGaugesOtherClassesAndMalformedOverridesAreUnchanged(): void
    {
        $this->rules([['type' => 'example-counter', 'display' => 'total']]);
        $gauge = $this->counter(['rrd_type' => 'GAUGE', 'sensor_current' => 2]);
        $this->assertFalse($gauge->displaysCounterTotal());
        $this->assertSame('2 ', $gauge->formatValue());
        $this->assertFalse($this->counter(['sensor_class' => 'temperature'])->displaysCounterTotal());
        LibrenmsConfig::set('os.counter-example.sensor_counter_display', 'invalid');
        $this->assertFalse($this->counter()->displaysCounterTotal());
        LibrenmsConfig::set('os.counter-example.sensor_counter_display', [null, 'invalid']);
        $this->assertFalse($this->counter()->displaysCounterTotal());
        $this->rules([['type' => 'example-counter', 'display' => 'unknown']]);
        $this->assertFalse($this->counter()->displaysCounterTotal());
    }

    public function testUcmOptsInThroughMetadataAndCanBeDisabled(): void
    {
        $sensor = $this->counter(['sensor_type' => 'grandstream-ucm'], 'grandstream-ucm');
        $this->assertTrue($sensor->displaysCounterTotal());
        $this->rules([], 'grandstream-ucm');
        $this->assertFalse($sensor->displaysCounterTotal());
        $this->assertSame('3.33 m ', $sensor->formatValue());
    }

    public function testNativeOverviewSeparatesTotalsWithoutChangingUnrelatedGroups(): void
    {
        $this->rules([['type' => 'example-counter', 'display' => 'total']]);
        $this->app->setLocale('en');
        $device = Device::factory()->create(['os' => 'counter-example'])->refresh();
        $ids = [];
        foreach ([
            ['Total Calls', 'COUNTER', 'example-counter', 'Calls', 185145],
            ['Concurrent Calls', 'GAUGE', 'example-counter', 'Calls', 2],
            ['Other rate', 'COUNTER', 'other-counter', 'Other', 42],
        ] as [$description, $type, $sensorType, $group, $current]) {
            $sensor = Sensor::factory()->create([
                'device_id' => $device->device_id, 'sensor_class' => 'count', 'sensor_type' => $sensorType,
                'sensor_descr' => $description, 'rrd_type' => $type, 'sensor_current' => $current, 'group' => $group,
            ]);
            $ids[$description] = $sensor->sensor_id;
        }
        DeviceCache::flush();
        $data = (new OverviewController)->data($device->fresh(), Request::create('/device/1'));
        $html = view('components.device.overview.sensors', [
            'device' => $device, 'panels' => $data['sensorPanels'],
        ])->render();
        $this->assertStringContainsString('Calls — Current values', $html);
        $this->assertStringContainsString('Calls — Cumulative totals', $html);
        $this->assertStringContainsString('185,145', $html);
        $this->assertStringContainsString('totals may reset', $html);
        $this->assertStringContainsString('Other</div>', $html);
        $this->assertStringNotContainsString('Other — Current values', $html);
        $this->assertLessThan(strpos($html, 'Calls — Cumulative totals'), strpos($html, 'Concurrent Calls'));
        $this->assertMatchesRegularExpression('/\bid=' . $ids['Concurrent Calls'] . '(?!\d)/', $html);
        $this->assertMatchesRegularExpression('/\bid=' . $ids['Other rate'] . '(?!\d)/', $html);
        $this->assertDoesNotMatchRegularExpression('/\bid=' . $ids['Total Calls'] . '(?!\d)/', $html);
        $this->rules([]);
        $defaultData = (new OverviewController)->data($device->fresh(), Request::create('/device/1'));
        $defaultHtml = view('components.device.overview.sensors', [
            'device' => $device, 'panels' => $defaultData['sensorPanels'],
        ])->render();
        $this->assertStringNotContainsString('Cumulative totals', $defaultHtml);
        $this->assertStringNotContainsString('Calls — Current values', $defaultHtml);
        $this->assertMatchesRegularExpression('/\bid=' . $ids['Total Calls'] . '(?!\d)/', $defaultHtml);
    }

    public function testHealthLabelsOptedInTotalsAndKeepsGaugeHistory(): void
    {
        $this->rules([['type' => 'example-counter', 'display' => 'total']]);
        $deviceModel = Device::factory()->create(['os' => 'counter-example'])->refresh();
        $ids = [];
        foreach ([['Total Calls', 'COUNTER', 185145], ['Concurrent Calls', 'GAUGE', 2]] as [$description, $type, $current]) {
            $sensor = Sensor::factory()->create([
                'device_id' => $deviceModel->device_id, 'sensor_class' => 'count', 'sensor_type' => 'example-counter',
                'sensor_descr' => $description, 'rrd_type' => $type, 'sensor_current' => $current,
            ]);
            $ids[$type] = $sensor->sensor_id;
        }
        DeviceCache::flush();
        DeviceCache::setPrimary($deviceModel->device_id);
        $class = SensorType::Count;
        $device = $deviceModel->toArray();
        $vars = ['page' => 'device', 'device' => $deviceModel->device_id];
        ob_start();
        include base_path('includes/html/pages/device/health/sensors.inc.php');
        $html = ob_get_clean();
        $this->assertIsString($html);
        $this->assertStringContainsString('Cumulative total: 185,145', $html);
        $this->assertStringContainsString('Current: 2', $html);
        $this->assertStringContainsString('this value may reset', $html);
        $this->assertMatchesRegularExpression('/\bid=' . $ids['GAUGE'] . '(?!\d)/', $html);
        $this->assertDoesNotMatchRegularExpression('/\bid=' . $ids['COUNTER'] . '(?!\d)/', $html);
        $this->rules([]);
        ob_start();
        include base_path('includes/html/pages/device/health/sensors.inc.php');
        $defaultHtml = ob_get_clean();
        $this->assertIsString($defaultHtml);
        $this->assertStringNotContainsString('Cumulative total', $defaultHtml);
        $this->assertMatchesRegularExpression('/\bid=' . $ids['COUNTER'] . '(?!\d)/', $defaultHtml);
    }

    public function testSharedSensorTableOmitsRateGraphsForTotalsInBothViews(): void
    {
        $this->rules([['type' => 'example-counter', 'display' => 'total']]);
        $device = Device::factory()->create(['os' => 'counter-example'])->refresh();
        $sensor = $this->counter(['device_id' => $device->device_id]);
        $sensor->setRelation('device', $device);
        foreach (['detail', 'graphs'] as $mode) {
            RequestFacade::swap(Request::create('/sensors', 'GET', ['view' => $mode]));
            $row = (new SensorsController)->formatItem($sensor);
            $this->assertSame('Cumulative total', $row['graph']);
            $this->assertStringContainsString('185,145', $row['sensor_current']);
            $this->assertStringNotContainsString('<img', implode('', $row));
        }
        $this->rules([]);
        $row = (new SensorsController)->formatItem($sensor);
        $this->assertStringContainsString('<img', $row['graph']);
    }

    public function testStandaloneRateGraphsUsePerSecondUnitsWithoutRawTotalThresholds(): void
    {
        $this->rules([['type' => 'example-counter', 'display' => 'total']]);
        $sensor = $this->counter(['sensor_limit' => 250000]);
        $rrd_filename = '/tmp/example-counter.rrd';
        $graph_params = new \stdClass;
        $rrd_options = [];
        $to = time();
        include base_path('includes/html/graphs/sensor/generic.inc.php');
        $this->assertSame('Count /s', $graph_params->vertical_label);
        $this->assertSame('%5.1lf/s', $graph_params->left_axis_format);
        $this->assertStringNotContainsString('LINE1.5:250000', implode(' ', $rrd_options));
        $this->assertStringNotContainsString('Alert thresholds', implode(' ', $rrd_options));
        $this->assertStringContainsString('GPRINT:sensor:LAST:%7.1lf%S/s', implode(' ', $rrd_options));
        $this->rules([]);
        $rrd_options = [];
        include base_path('includes/html/graphs/sensor/generic.inc.php');
        $this->assertSame('Count', $graph_params->vertical_label);
        $this->assertStringContainsString('LINE1.5:250000', implode(' ', $rrd_options));
    }

    public function testPortSensorPanelLabelsRetainedRateGraphs(): void
    {
        $this->rules([['type' => 'example-counter', 'display' => 'total']]);
        $deviceModel = Device::factory()->create(['os' => 'counter-example'])->refresh();
        $sensor = Sensor::factory()->create([
            'device_id' => $deviceModel->device_id, 'sensor_class' => 'count', 'sensor_type' => 'example-counter',
            'sensor_current' => 185145, 'rrd_type' => 'COUNTER',
            'entPhysicalIndex' => 5, 'entPhysicalIndex_measured' => 'ports',
        ]);
        DeviceCache::flush();
        DeviceCache::setPrimary($deviceModel->device_id);
        $device = $deviceModel->toArray();
        $port = ['ifIndex' => 5];
        $vars = ['page' => 'device', 'device' => $deviceModel->device_id];
        ob_start();
        include base_path('includes/html/pages/device/port/sensors.inc.php');
        $html = ob_get_clean();
        $this->assertIsString($html);
        $this->assertStringContainsString('Cumulative total: 185,145', $html);
        $this->assertStringContainsString('Rate of change', $html);
        $this->assertMatchesRegularExpression('/\bid=' . $sensor->sensor_id . '(?!\d)/', $html);
    }

    public function testSchemaAcceptsRulesAndRejectsUnsupportedModesAndKeys(): void
    {
        $definition = Yaml::parseFile(resource_path('definitions/os_detection/grandstream-ucm.yaml'));
        foreach ([
            [[['type' => 'example-counter', 'display' => 'total']], true],
            [[['type' => 'example-counter', 'index' => 'x.0', 'display' => 'rate']], true],
            [[['type' => 'example-counter', 'display' => 'daily']], false],
            [[['type' => 'example-counter', 'display' => 'total', 'typo' => true]], false],
            [[['display' => 'total']], false],
        ] as [$rules, $valid]) {
            $definition['sensor_counter_display'] = $rules;
            $object = json_decode(json_encode($definition, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
            $validator = new Validator;
            $validator->validate($object, (object) [
                '$ref' => 'file://' . resource_path('definitions/schema/os_schema.json'),
            ]);
            $this->assertSame($valid, $validator->isValid(), json_encode($validator->getErrors(), JSON_THROW_ON_ERROR));
        }
    }
}
