<?php

declare(strict_types=1);

namespace DK\CompoundFile\Internal;

use DK\CompoundFile\DirectoryEntry;

/** @internal Builds the red-black sibling trees serialized in a CFBF directory. */
final class DirectoryTreeBuilder
{
    private const NONE = 0xFFFFFFFF;

    /**
     * @param list<WritableEntry> $entries Root-first entries with existing storage parents and unique normalized paths.
     * @return array<int, WritableTreeNode>
     */
    public function build(array $entries): array
    {
        $tree = [];
        $idsByParent = [];
        foreach ($entries as $id => $entry) {
            $tree[$id] = new WritableTreeNode();
            if ($id !== 0) {
                $idsByParent[PathNormalizer::normalize($this->parentPath($entry->path))][] = $id;
            }
        }

        $idByPath = [];
        foreach ($entries as $id => $entry) {
            $idByPath[PathNormalizer::normalize($entry->path)] = $id;
        }
        foreach ($idsByParent as $parent => $ids) {
            $parentId = $idByPath[$parent];
            $tree[$parentId]->child = $this->buildSiblingTree($ids, $entries, $tree);
        }

        return $tree;
    }

    /**
     * @param list<int> $ids
     * @param list<WritableEntry> $entries
     * @param array<int, WritableTreeNode> $tree
     */
    private function buildSiblingTree(array $ids, array $entries, array &$tree): int
    {
        usort($ids, static fn (int $left, int $right): int => self::compareEntries($entries[$left], $entries[$right]));

        $root = self::NONE;
        foreach ($ids as $id) {
            $tree[$id]->left = self::NONE;
            $tree[$id]->right = self::NONE;
            $tree[$id]->parent = self::NONE;
            $tree[$id]->color = DirectoryEntry::COLOR_RED;
            $previous = self::NONE;
            $current = $root;
            while ($current !== self::NONE) {
                $previous = $current;
                $current = self::compareEntries($entries[$id], $entries[$current]) < 0
                    ? $tree[$current]->left
                    : $tree[$current]->right;
            }
            $tree[$id]->parent = $previous;
            if ($previous === self::NONE) {
                $root = $id;
            } elseif (self::compareEntries($entries[$id], $entries[$previous]) < 0) {
                $tree[$previous]->left = $id;
            } else {
                $tree[$previous]->right = $id;
            }
            $this->fixInsertion($id, $root, $tree);
        }
        if ($root !== self::NONE) {
            $tree[$root]->color = DirectoryEntry::COLOR_BLACK;
        }

        return $root;
    }

    /** @param array<int, WritableTreeNode> $tree */
    private function fixInsertion(int $node, int &$root, array &$tree): void
    {
        while ($node !== $root && $tree[$node]->parent !== self::NONE
            && $tree[$tree[$node]->parent]->color === DirectoryEntry::COLOR_RED) {
            $parent = $tree[$node]->parent;
            $grandparent = $tree[$parent]->parent;
            if ($parent === $tree[$grandparent]->left) {
                $uncle = $tree[$grandparent]->right;
                if ($uncle !== self::NONE && $tree[$uncle]->color === DirectoryEntry::COLOR_RED) {
                    $tree[$parent]->color = DirectoryEntry::COLOR_BLACK;
                    $tree[$uncle]->color = DirectoryEntry::COLOR_BLACK;
                    $tree[$grandparent]->color = DirectoryEntry::COLOR_RED;
                    $node = $grandparent;
                    continue;
                }
                if ($node === $tree[$parent]->right) {
                    $node = $parent;
                    $this->rotateLeft($node, $root, $tree);
                    $parent = $tree[$node]->parent;
                    $grandparent = $tree[$parent]->parent;
                }
                $tree[$parent]->color = DirectoryEntry::COLOR_BLACK;
                $tree[$grandparent]->color = DirectoryEntry::COLOR_RED;
                $this->rotateRight($grandparent, $root, $tree);
            } else {
                $uncle = $tree[$grandparent]->left;
                if ($uncle !== self::NONE && $tree[$uncle]->color === DirectoryEntry::COLOR_RED) {
                    $tree[$parent]->color = DirectoryEntry::COLOR_BLACK;
                    $tree[$uncle]->color = DirectoryEntry::COLOR_BLACK;
                    $tree[$grandparent]->color = DirectoryEntry::COLOR_RED;
                    $node = $grandparent;
                    continue;
                }
                if ($node === $tree[$parent]->left) {
                    $node = $parent;
                    $this->rotateRight($node, $root, $tree);
                    $parent = $tree[$node]->parent;
                    $grandparent = $tree[$parent]->parent;
                }
                $tree[$parent]->color = DirectoryEntry::COLOR_BLACK;
                $tree[$grandparent]->color = DirectoryEntry::COLOR_RED;
                $this->rotateLeft($grandparent, $root, $tree);
            }
        }
        $tree[$root]->color = DirectoryEntry::COLOR_BLACK;
    }

    /** @param array<int, WritableTreeNode> $tree */
    private function rotateLeft(int $node, int &$root, array &$tree): void
    {
        $right = $tree[$node]->right;
        $tree[$node]->right = $tree[$right]->left;
        if ($tree[$right]->left !== self::NONE) {
            $tree[$tree[$right]->left]->parent = $node;
        }
        $tree[$right]->parent = $tree[$node]->parent;
        if ($tree[$node]->parent === self::NONE) {
            $root = $right;
        } elseif ($node === $tree[$tree[$node]->parent]->left) {
            $tree[$tree[$node]->parent]->left = $right;
        } else {
            $tree[$tree[$node]->parent]->right = $right;
        }
        $tree[$right]->left = $node;
        $tree[$node]->parent = $right;
    }

    /** @param array<int, WritableTreeNode> $tree */
    private function rotateRight(int $node, int &$root, array &$tree): void
    {
        $left = $tree[$node]->left;
        $tree[$node]->left = $tree[$left]->right;
        if ($tree[$left]->right !== self::NONE) {
            $tree[$tree[$left]->right]->parent = $node;
        }
        $tree[$left]->parent = $tree[$node]->parent;
        if ($tree[$node]->parent === self::NONE) {
            $root = $left;
        } elseif ($node === $tree[$tree[$node]->parent]->right) {
            $tree[$tree[$node]->parent]->right = $left;
        } else {
            $tree[$tree[$node]->parent]->left = $left;
        }
        $tree[$left]->right = $node;
        $tree[$node]->parent = $left;
    }

    private static function compareEntries(WritableEntry $left, WritableEntry $right): int
    {
        return CfbfNameComparator::compare($left->name, $right->name);
    }

    private function parentPath(string $path): string
    {
        $position = strrpos($path, '/');

        return $position === false ? '' : substr($path, 0, $position);
    }
}
