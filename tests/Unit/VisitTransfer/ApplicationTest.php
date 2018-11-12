<?php

    namespace Tests\Unit\VisitTransfer;

    use App\Models\Mship\Qualification;
    use App\Models\NetworkData\Atc;
    use App\Models\VisitTransfer\Application;
    use App\Notifications\ApplicationAccepted;
    use Carbon\Carbon;
    use Faker\Provider\Base;
    use Illuminate\Foundation\Testing\DatabaseTransactions;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\Mail;
    use Illuminate\Support\Facades\Notification;
    use Illuminate\Support\Facades\View;
    use Tests\TestCase;

    class ApplicationTest extends TestCase
    {
        use DatabaseTransactions;

        /** Unit Testing */

        /** @test */
        public function itCanCreateANewApplicationForAUser()
        {
            $account = factory(\App\Models\Mship\Account::class)->create();
            $account->addState(\App\Models\Mship\State::findByCode('INTERNATIONAL'));

            $this->assertCount(0, $account->visitTransferApplications);

            $account->createVisitingTransferApplication([
                'type' => Application::TYPE_VISIT,
            ]);

            $this->assertCount(1, $account->fresh()->visitTransferApplications);
            $this->assertCount(1, $account->fresh()->visitApplications);
        }

        /** @test */
        public function itThrowsAnExceptionWhenAttemptingToCreateADuplicateApplication()
        {
            $this->expectException(\App\Exceptions\VisitTransfer\Application\DuplicateApplicationException::class);

            $account = factory(\App\Models\Mship\Account::class)->create();
            $account->addState(\App\Models\Mship\State::findByCode('INTERNATIONAL'));

            $this->assertCount(0, $account->visitTransferApplications);

            $account->fresh()->createVisitingTransferApplication([
                'type' => Application::TYPE_VISIT,
            ]);

            $account->fresh()->createVisitingTransferApplication([
                'type' => Application::TYPE_VISIT,
            ]);
        }

        /** @test */
        public function itThrowsAnExceptionWhenAttemptingToCreateAnApplicationForADivisionMember()
        {
            $this->expectException(\App\Exceptions\VisitTransfer\Application\AlreadyADivisionMemberException::class);

            $account = factory(\App\Models\Mship\Account::class)->create();
            $account->addState(\App\Models\Mship\State::findByCode('DIVISION'));

            $this->assertCount(0, $account->visitTransferApplications);

            $account->fresh()->createVisitingTransferApplication([
                'type' => Application::TYPE_VISIT,
            ]);
        }

        /** @test */
        public function itCorrectlyReports50HourCheck()
        {
            Mail::fake();

            $account = factory(\App\Models\Mship\Account::class)->create();
            $qual = Qualification::code('S2')->first();
            $account->addQualification($qual)->save();

            $application = factory(Application::class, 'atc_transfer')->create([
                'account_id' => $account->id,
                'status' => Application::STATUS_SUBMITTED,
                'should_perform_checks' => 1,
            ]);

            // Add 49 hours of ATC
            $start = new Carbon('80 hours ago');
            $end = new Carbon('31 hours ago');
            $atc = factory(Atc::class, 'offline')->create([
                'account_id' => $account->id,
                'qualification_id' => $qual->id,
                'connected_at' => $start,
                'disconnected_at' => $end,
                'minutes_online' => $start->diffInMinutes($end),
            ]);

            $this->assertFalse($application->check50Hours());

            // Add 1 hour of ATC
            $end = new Carbon('30 hour ago');
            $atc->disconnected_at = $end;
            $atc->minutes_online = $start->diffInMinutes($end);
            $atc->save();

            $this->assertTrue($application->check50Hours());
        }

        /** @test */
        public function itDisregardsAtcOfDifferentQualificationFor50HourCheck()
        {
            Mail::fake();

            $account = factory(\App\Models\Mship\Account::class)->create();
            $qual = Qualification::code('S2')->first();
            $account->addQualification($qual);
            $account->save();

            $application = factory(Application::class, 'atc_transfer')->create([
                'account_id' => $account->id,
                'status' => Application::STATUS_SUBMITTED,
                'should_perform_checks' => 1,
            ]);

            // Add 60 hours of ATC
            $start = new Carbon('80 hours ago');
            $end = new Carbon('20 hours ago');
            factory(Atc::class, 'offline')->create([
                'account_id' => $account->id,
                'qualification_id' => Qualification::code('S1')->first()->id,
                'connected_at' => $start,
                'disconnected_at' => $end,
                'minutes_online' => $start->diffInMinutes($end),
            ]);

            $this->assertFalse($application->check50Hours());
        }

        /** @test */
        public function itCorrectlyReports90DayCheck()
        {
            $account = factory(\App\Models\Mship\Account::class)->create();
            $qual = Qualification::code('S2')->first();
            $account->addQualification($qual);
            $account->save();

            $application = factory(Application::class, 'atc_transfer')->create([
                'account_id' => $account->id,
                'status' => Application::STATUS_SUBMITTED,
                'should_perform_checks' => 1,
                'submitted_at' => now(),
            ]);

            $this->assertFalse($application->fresh()->check90DayQualification());
            $account->qualifications()->updateExistingPivot($qual->id, ['created_at' => new Carbon('100 days ago')]);
            $this->assertTrue($application->fresh()->check90DayQualification());
        }

        /** @test */
        public function itSendsAcceptanceEmailToTrainingTeam()
        {
            Notification::fake();
            $account = factory(\App\Models\Mship\Account::class)->create();
            $account->addState(\App\Models\Mship\State::findByCode('INTERNATIONAL'));

            $facility = factory(\App\Models\VisitTransfer\Facility::class, 'atc_visit')->create();

            $application = $account->fresh()->createVisitingTransferApplication([
                'type' => Application::TYPE_VISIT,
                'facility_id' => $facility->id,
                'training_team' => $facility->training_team,
                'status' => Application::STATUS_UNDER_REVIEW,
            ]);

            $application->accept();

            Notification::assertSentTo($facility, ApplicationAccepted::class, function ($notification, $channels) use ($application, $facility) {
                $mail = $notification->toMail($facility);
                $view = View::make($mail->view, $mail->viewData)->render();

                $this->assertContains('Dear ATC Training Team,', $view);

                return $notification->application->id == $application->id;
            });
        }

        /** @test */
        public function itReportsStatisticsCorrectly()
        {
            $applicationTypes = [
                'statisticTotal' => collect(Application::$APPLICATION_IS_CONSIDERED_EDITABLE)->merge(Application::$APPLICATION_IS_CONSIDERED_OPEN)->merge(Application::$APPLICATION_IS_CONSIDERED_CLOSED)->merge(Application::$APPLICATION_REQUIRES_ACTION)->merge(Application::$APPLICATION_IS_CONSIDERED_WITHDRAWABLE)->unique()->all(),
                'statisticOpen' => Application::$APPLICATION_IS_CONSIDERED_OPEN,
                'statisticUnderReview' => [Application::STATUS_UNDER_REVIEW],
                'statisticAccepted' => [Application::STATUS_ACCEPTED],
                'statisticClosed' => Application::$APPLICATION_IS_CONSIDERED_CLOSED,
            ];

            // Check initially zero

            foreach ($applicationTypes as $function => $status) {
                $this->assertEquals(0, Application::$function());
            }

            // Create some applications

            factory(Application::class, 20)->create([
                'status' => function () use ($applicationTypes) {
                    return Base::randomElement(Base::randomElement($applicationTypes));
                }
            ]);

            // Test
            Cache::flush();
            foreach ($applicationTypes as $function => $status) {
                $this->assertEquals(Application::statusIn($status)->count(), Application::$function());
            }
        }
    }
