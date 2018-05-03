<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Mautic, Inc.
 *
 * @link        https://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\CampaignBundle\Tests\Command;

class ValidateEventCommandTest extends AbstractCampaignCommand
{
    public function testEventsAreExecutedForInactiveEventWithSingleContact()
    {
        $this->runCommand('mautic:campaigns:trigger', ['-i' => 1, '--contact-id' => 1]);

        // Wait 15 seconds then execute the campaign again to send scheduled events
        sleep(15);
        $this->runCommand('mautic:campaigns:trigger', ['-i' => 1, '--contact-id' => 1]);

        // No open email decisions should be recorded yet
        $byEvent = $this->getCampaignEventLogs([3]);
        $this->assertCount(0, $byEvent[3]);

        // Wait 15 seconds to go beyond the inaction timeframe
        sleep(15);

        // Now they should be inactive
        $this->runCommand('mautic:campaigns:validate', ['--decision-id' => 3, '--contact-id' => 1]);

        $byEvent = $this->getCampaignEventLogs([3, 7, 10]);
        $this->assertCount(1, $byEvent[3]); // decision recorded
        $this->assertCount(1, $byEvent[7]); // inactive event executed
        $this->assertCount(0, $byEvent[10]); // the positive path should be 0
    }

    public function testEventsAreExecutedForInactiveEventWithMultipleContact()
    {
        $this->runCommand('mautic:campaigns:trigger', ['-i' => 1, '--contact-ids' => '1,2,3']);

        // Wait 15 seconds then execute the campaign again to send scheduled events
        sleep(15);
        $this->runCommand('mautic:campaigns:trigger', ['-i' => 1, '--contact-ids' => '1,2,3']);

        // No open email decisions should be recorded yet
        $byEvent = $this->getCampaignEventLogs([3]);
        $this->assertCount(0, $byEvent[3]);

        // Wait 15 seconds to go beyond the inaction timeframe
        sleep(15);

        // Now they should be inactive
        $this->runCommand('mautic:campaigns:validate', ['--decision-id' => 3, '--contact-ids' => '1,2,3']);

        $byEvent = $this->getCampaignEventLogs([3, 7, 10]);
        $this->assertCount(3, $byEvent[3]); // decision recorded
        $this->assertCount(3, $byEvent[7]); // inactive event executed
        $this->assertCount(0, $byEvent[10]); // the positive path should be 0
    }
}