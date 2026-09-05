<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * XTMK's own bigger lists exposed the previous pagination() — one <li>
 * per page — as a literal screen-width row of buttons once a list grew
 * past a couple dozen pages. Pure function, no DB, so a plain unit test
 * (App_skeleton\ListView doesn't touch the DI container at all).
 */
final class ListViewPaginationTest extends TestCase
{
    public function testSinglePageRendersNothing(): void
    {
        $this->assertSame('', \App_skeleton\ListView::pagination('/backend/users', 1, 1));
    }

    public function testSmallTotalShowsEveryPageWithNoEllipsis(): void
    {
        $html = \App_skeleton\ListView::pagination('/backend/users', 3, 6);

        for ($i = 1; $i <= 6; $i++) {
            $this->assertStringContainsString('page=' . $i . '"', $html);
        }
        $this->assertStringNotContainsString('page-jump', $html);
        $this->assertStringContainsString('First', $html);
        $this->assertStringContainsString('Last', $html);
    }

    public function testLargeTotalShowsOnlyThreeSmallestAndThreeLargestPlusEllipsis(): void
    {
        $html = \App_skeleton\ListView::pagination('/backend/users', 5, 50);

        foreach ([1, 2, 3, 48, 49, 50] as $expected) {
            $this->assertStringContainsString('page=' . $expected . '"', $html);
        }
        // Checking for the rendered link *text* (">4<"), not a "page=4"
        // substring — page 4 legitimately appears in the Prev link's own
        // href (page 5's prev is page=4) without page 4 having its own
        // numbered button.
        foreach ([4, 47] as $hidden) {
            $this->assertStringNotContainsString('>' . $hidden . '<', $html);
        }
        $this->assertStringContainsString('page-jump', $html, 'the "…" jump control is expected once there is a real gap');
        $this->assertStringContainsString('data-total-pages="50"', $html);
    }

    public function testCurrentPageIsMarkedActive(): void
    {
        $html = \App_skeleton\ListView::pagination('/backend/users', 2, 6);

        $this->assertMatchesRegularExpression('/page-item active"><a[^>]*>2</', $html);
    }

    public function testFirstAndPrevAreDisabledOnPageOne(): void
    {
        $html = \App_skeleton\ListView::pagination('/backend/users', 1, 10);

        $this->assertMatchesRegularExpression('/page-item disabled"><span class="page-link">&laquo; First/', $html);
        $this->assertMatchesRegularExpression('/page-item disabled"><span class="page-link">&laquo; Prev/', $html);
    }

    public function testNextAndLastAreDisabledOnTheFinalPage(): void
    {
        $html = \App_skeleton\ListView::pagination('/backend/users', 10, 10);

        $this->assertMatchesRegularExpression('/page-item disabled"><span class="page-link">Next &raquo;/', $html);
        $this->assertMatchesRegularExpression('/page-item disabled"><span class="page-link">Last &raquo;/', $html);
    }

    public function testPreserveIsCarriedIntoTheEllipsisJumpControl(): void
    {
        $html = \App_skeleton\ListView::pagination('/backend/users', 5, 50, ['q' => 'smith', 'sort' => 'email']);

        $this->assertStringContainsString('data-preserve="', $html);
        $this->assertStringContainsString('smith', $html);
    }
}
