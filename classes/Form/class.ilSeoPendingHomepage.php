<?php

/**
 * This file is part of Seo UI Plugin for ILIAS,
 * developed by OC Open Consulting to enable
 * SEO functionalities in ILIAS.
 *
 * @author Vincenzo Padula <vincenzo@oc-group.eu>
 * @copyright 2026 OC Open Consulting SB Srl
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

/**
 * The one pending SEO save this session defers for a homepage confirmation: the
 * row's would-be new values, stashed when
 * quickSave()/save()/ilSeoOverviewGUI::updatePage() find an empty, unclaimed
 * permalink and pause for the admin's yes/no instead of writing it.
 *
 * A single session slot, not one per target, matching this plugin's own
 * "seo_alerts" session key. Starting a second confirmation before answering the
 * first simply overwrites it; the abandoned draft is lost at no cost, since
 * nothing was ever written for it.
 *
 * Read with peek()/peekFor(), never consumed automatically, so a stashed draft
 * survives repeated page views. Cleared only by clear()/clearIfMatches(),
 * called from every save path once it writes successfully, so a real save
 * always retires a stale draft. If the admin abandons the confirmation
 * entirely, the draft either resurfaces as a prefill next time, or expires with
 * the session; either way nothing was ever persisted for it.
 */
class ilSeoPendingHomepage
{
    /** @var string ilSession key; one slot for the whole session, see class docblock */
    private const SESSION_KEY = "seo_pending_homepage";

    /**
     * Stash a submitted row waiting on the admin's homepage confirmation.
     * @param int $ref_id
     * @param int $secondary_id
     * @param string $lang stored-row language, "-" for the default-language row
     * @param string $title
     * @param string $description
     * @param string $robots
     * @param int $priority
     * @param string $frequency
     * @param string $return_url where both the confirm and the cancel action
     *     lead back to
     * @return void
     */
    public static function stash(
        int $ref_id,
        int $secondary_id,
        string $lang,
        string $title,
        string $description,
        string $robots,
        int $priority,
        string $frequency,
        string $return_url
    ): void {
        ilSession::set(self::SESSION_KEY, [
            "ref_id" => $ref_id,
            "secondary_id" => $secondary_id,
            "lang" => $lang,
            "title" => $title,
            "description" => $description,
            "robots" => $robots,
            "priority" => $priority,
            "frequency" => $frequency,
            "return_url" => $return_url,
        ]);
    }

    /**
     * The stashed draft, whichever row it names. confirmHomepage() and
     * applyHomepage() check the identity themselves against the ref_id the
     * request already named.
     * @return array<string,mixed>|null
     */
    public static function peek(): ?array
    {
        $pending = ilSession::get(self::SESSION_KEY);

        return is_array($pending) ? $pending : null;
    }

    /**
     * The stashed draft, only when it names exactly this row: what a form
     * building its initial values calls, so a draft for a different page can
     * never leak into this one.
     * @param int $ref_id
     * @param int $secondary_id
     * @param string $lang
     * @return array<string,mixed>|null
     */
    public static function peekFor(int $ref_id, int $secondary_id, string $lang): ?array
    {
        $pending = self::peek();
        if ($pending === null) {
            return null;
        }

        $matches = (int) ($pending["ref_id"] ?? null) === $ref_id
            && (int) ($pending["secondary_id"] ?? null) === $secondary_id
            && (string) ($pending["lang"] ?? null) === $lang;

        return $matches ? $pending : null;
    }

    /**
     * Whether a draft matching this exact row exists, without needing its
     * contents. What a caller asks before deciding whether to show the "these
     * values are unsaved" notice next to a form mergeIntoSeoData() has already
     * overlaid.
     * @param int $ref_id
     * @param int $secondary_id
     * @param string $lang
     * @return bool
     */
    public static function isPendingFor(int $ref_id, int $secondary_id, string $lang): bool
    {
        return self::peekFor($ref_id, $secondary_id, $lang) !== null;
    }

    /**
     * Drop the stashed draft outright. applyHomepage() calls this once its own
     * save succeeds, or once it discovers the slot has since been claimed by
     * someone else.
     * @return void
     */
    public static function clear(): void
    {
        ilSession::clear(self::SESSION_KEY);
    }

    /**
     * Drop the stashed draft only when it belongs to the row a save just
     * succeeded for. Every save path calls this right after a successful
     * ilSeo::save(), so a stray permalink="" draft can never resurface once its
     * row has genuinely been written with any value.
     * @param int $ref_id
     * @param int $secondary_id
     * @param string $lang
     * @return void
     */
    public static function clearIfMatches(int $ref_id, int $secondary_id, string $lang): void
    {
        if (self::peekFor($ref_id, $secondary_id, $lang) !== null) {
            self::clear();
        }
    }

    /**
     * Overlay a stashed draft matching this exact row onto the row read from
     * storage, so every caller that builds a form's initial values sees the
     * admin's unsaved edit instead of (or in the absence of) the saved row,
     * with neither ilSeoInitialValues nor ilSeoMetadataEditor needing to know a
     * draft exists at all.
     * @param array<string,mixed>|null $seo_data the row as stored, null when
     *     there is none
     * @param int $ref_id
     * @param int $secondary_id
     * @param string $lang
     * @return array<string,mixed>|null
     */
    public static function mergeIntoSeoData(?array $seo_data, int $ref_id, int $secondary_id, string $lang): ?array
    {
        $pending = self::peekFor($ref_id, $secondary_id, $lang);
        if ($pending === null) {
            return $seo_data;
        }

        return array_merge($seo_data ?? [], [
            "permalink" => "",
            "title" => $pending["title"],
            "description" => $pending["description"],
            "robots" => $pending["robots"],
            "priority" => $pending["priority"],
            "frequency" => $pending["frequency"],
        ]);
    }
}
