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
 * Whether repository objects are still reachable in the tree they belong to.
 * One predicate, asked in two shapes: the graph builder asks it for every
 * SEO-enabled page at once, a permalink request asks it for the single ref_id
 * it just resolved.
 *
 * Trashing a subtree negates its tree id, so a node whose row no longer carries
 * the live tree id is in the trash, and a ref_id with no row left was purged.
 * "In this tree" and "not trashed" are therefore the same question, which is
 * why one query answers both.
 *
 * Static rather than injected: one caller runs during a permalink dispatch,
 * outside any constructed object graph.
 */
final class ilSeoReachability
{
    /**
     * Whether one object is still reachable.
     *
     * Delegates to the set form on purpose: with a single element the IN() list
     * degenerates to an equality lookup on the tree table's own key, so a
     * separate hand-written query would cost the same and give the two callers
     * two predicates that can drift apart.
     * @param ilDBInterface $db
     * @param ilTree $tree
     * @param int $ref_id
     * @return bool
     */
    public static function isReachable(ilDBInterface $db, ilTree $tree, int $ref_id): bool
    {
        return self::unreachableRefIds($db, $tree, [$ref_id]) === [];
    }

    /**
     * The subset of the given ref_ids whose object is no longer reachable.
     *
     * One query for the whole set rather than an ilTree::isInTree() call per
     * ref_id: that method resolves a single child at a time and core exposes no
     * set-based equivalent, which would make a graph-wide check an N+1 over
     * every SEO-enabled page. The ref_ids the query returns are the reachable
     * ones, so the remainder is the answer. Table name, tree-id column and tree
     * id come from the ilTree instance rather than literals, so this stays
     * bound to the tree handed in.
     *
     * @api Signature is a contract for an outside consumer: do not
     * change or remove it casually.
     * @param ilDBInterface $db
     * @param ilTree $tree
     * @param int[] $ref_ids
     * @return array<int,bool> ref_id => true
     */
    public static function unreachableRefIds(ilDBInterface $db, ilTree $tree, array $ref_ids): array
    {
        $unreachable = [];
        foreach ($ref_ids as $ref_id) {
            $unreachable[(int) $ref_id] = true;
        }

        if ($unreachable === []) {
            return [];
        }

        $res = $db->query(
            "SELECT child FROM " . $tree->getTreeTable() . "
            WHERE " . $db->in("child", array_keys($unreachable), false, "integer") . "
            AND " . $tree->getTreePk() . " = " . $db->quote($tree->getTreeId(), "integer")
        );
        while ($row = $db->fetchAssoc($res)) {
            unset($unreachable[(int) $row["child"]]);
        }

        return $unreachable;
    }
}
