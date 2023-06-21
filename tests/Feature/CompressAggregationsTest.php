<?php

namespace Remp\BeamModule\Tests\Feature;

use Remp\BeamModule\Model\Article;
use Remp\BeamModule\Model\ArticlePageviews;
use Remp\BeamModule\Model\ArticleTimespent;
use Remp\BeamModule\Console\Commands\CompressAggregations;
use Remp\BeamModule\Model\Property;
use Remp\BeamModule\Model\SessionDevice;
use Remp\BeamModule\Model\SessionReferer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Remp\BeamModule\Tests\TestCase;

class CompressAggregationsTest extends TestCase
{
    use RefreshDatabase;

    private $thresholdPeriod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->thresholdPeriod = config('beam.aggregated_data_retention_period');
    }

    public function testPageviewsAggregations()
    {
        $this->runAndTestArticleAggregations(ArticlePageviews::class);
    }

    public function testTimespentAggregations()
    {
        $this->runAndTestArticleAggregations(ArticleTimespent::class);
    }

    public function testAggregateAllDaySessionReferers()
    {
        $start = Carbon::today()->subDays($this->thresholdPeriod + 1);
        for ($i = 0; $i < 24; $i++) {
            SessionReferer::factory()->create([
                'time_from' => (clone $start)->addHours($i),
                'time_to' => (clone $start)->addHours($i + 1),
                'subscriber' => false,
                'count' => 1,
                'medium' => 'a',
                'source' => 'a',
            ]);
        }

        $this->assertEquals(24, SessionReferer::all()->count());

        $this->artisan(CompressAggregations::COMMAND);

        $this->assertEquals(1, SessionReferer::all()->count());
    }

    public function testSessionDevicesAggregation()
    {
        $same = [
            'type' => 'a',
            'model' => 'a',
            'brand' => 'a',
            'os_name' => 'a',
            'os_version' => 'a',
            'client_type' => 'a',
            'client_name' => 'a',
            'client_version' => 'a',
        ];

        SessionDevice::factory()->create(array_merge($same, [
                'time_from' => Carbon::today()->hour(9)->subDays($this->thresholdPeriod),
                'time_to' => Carbon::today()->hour(10)->subDays($this->thresholdPeriod),
                'subscriber' => false,
                'count' => 10,
            ])
        );

        SessionDevice::factory()->create(array_merge($same, [
                'time_from' => Carbon::today()->hour(9)->subDays($this->thresholdPeriod),
                'time_to' => Carbon::today()->hour(10)->subDays($this->thresholdPeriod),
                'subscriber' => false,
                'count' => 5,
            ])
        );

        SessionDevice::factory()->create(array_merge($same, [
                'time_from' => Carbon::today()->hour(9)->subDays($this->thresholdPeriod),
                'time_to' => Carbon::today()->hour(10)->subDays($this->thresholdPeriod),
                'subscriber' => true,
                'count' => 2,
            ])
        );

        //// Do not aggregate these
        SessionDevice::factory()->create(array_merge($same, [
                'time_from' => Carbon::today()->hour(9)->subDays($this->thresholdPeriod - 1),
                'time_to' => Carbon::today()->hour(10)->subDays($this->thresholdPeriod - 1),
                'subscriber' => false,
                'count' => 6,
            ])
        );

        SessionDevice::factory()->create(array_merge($same, [
                'time_from' => Carbon::today()->hour(9)->subDays($this->thresholdPeriod - 1),
                'time_to' => Carbon::today()->hour(10)->subDays($this->thresholdPeriod - 1),
                'subscriber' => false,
                'count' => 3,
            ])
        );

        $this->artisan(CompressAggregations::COMMAND);

        $this->assertEquals(4, SessionDevice::all()->count());

        $d = Carbon::today()->subDays($this->thresholdPeriod);

        $this->assertEquals(15, SessionDevice::whereDate('time_from', $d)->where('subscriber', false)->first()->count);
    }


    public function runAndTestArticleAggregations($className)
    {
        $property = Property::factory()->create();

        $article1 = Article::factory()->create(['property_uuid' => $property->uuid]);
        $article2 = Article::factory()->create(['property_uuid' => $property->uuid]);

        // Prepare data to be aggregated
        $ag1 = $className::factory()->create([
            'article_id' => $article1->id,
            'time_from' => Carbon::today()->hour(9)->subDays(91),
            'time_to' => Carbon::today()->hour(10)->subDays(91),
        ]);

        $ag2 = $className::factory()->create([
            'article_id' => $article1->id,
            'time_from' => Carbon::today()->hour(10)->subDays(91),
            'time_to' => Carbon::today()->hour(11)->subDays(91),
        ]);

        $className::factory()->create([
            'article_id' => $article2->id,
            'time_from' => Carbon::today()->hour(10)->subDays(91),
            'time_to' => Carbon::today()->hour(11)->subDays(91),
        ]);

        $className::factory()->create([
            'article_id' => $article1->id,
            'time_from' => Carbon::today()->hour(10)->subDays(90),
            'time_to' => Carbon::today()->hour(11)->subDays(90),
        ]);

        // These won't be aggregated
        $className::factory()->create([
            'article_id' => $article1->id,
            'time_from' => Carbon::today()->hour(10)->subDays(89),
            'time_to' => Carbon::today()->hour(11)->subDays(89),
        ]);

        // Prepare asserts
        $runAsserts = function () use ($ag1, $ag2, $article1, $article2, $className) {
            $ag = $className::where('article_id', $article1->id)
                ->where('time_to', '<=', Carbon::today()->subDays(90))->first();

            $this->assertEquals($ag1->sum + $ag2->sum, $ag->sum);
            $this->assertEquals($ag1->signed_in + $ag2->signed_in, $ag->signed_in);
            $this->assertEquals($ag1->subscribers + $ag2->subscribers, $ag->subscribers);

            $this->assertEquals(3, $className::where('article_id', $article1->id)->count());
            $this->assertEquals(1, $className::where('article_id', $article2->id)->count());
        };

        // Run test
        $this->artisan(CompressAggregations::COMMAND);
        $runAsserts();

        // Make sure when command is run twice, results are the same
        $this->artisan(CompressAggregations::COMMAND);
        $runAsserts();
    }
}
