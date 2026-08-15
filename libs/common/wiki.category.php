<?php

function wiki_category_rows()
{
    return wiki_db()->query("SELECT id, parent_id, visibility FROM categories")->fetchAll();
}

/**
 * 자신 또는 상위 카테고리가 private 인 카테고리 id 목록.
 */
function wiki_private_category_ids()
{
    $rows = wiki_category_rows();
    $by_id = [];
    foreach ($rows as $row) {
        $by_id[(int) $row["id"]] = $row;
    }

    $hidden = [];
    foreach ($rows as $row) {
        $current = (int) $row["id"];
        $seen = [];
        while ($current > 0 && !isset($seen[$current])) {
            $seen[$current] = true;
            $item = $by_id[$current] ?? null;
            if (!$item) {
                break;
            }
            if ($item["visibility"] === "private") {
                $hidden[] = (int) $row["id"];
                break;
            }
            $current = (int) ($item["parent_id"] ?? 0);
        }
    }

    return array_values(array_unique($hidden));
}

function wiki_category_requires_login( $category_id )
{
    if (!$category_id) {
        return false;
    }
    return in_array((int) $category_id, wiki_private_category_ids(), true);
}

/**
 * 카테고리와 그 하위 카테고리 id 전체.
 */
function wiki_category_descendants( $category_id )
{
    $rows = wiki_db()->query("SELECT id, parent_id FROM categories")->fetchAll();
    $children = [];
    foreach ($rows as $row) {
        $children[(int) ($row["parent_id"] ?? 0)][] = (int) $row["id"];
    }

    $result = [];
    $stack = [(int) $category_id];
    while ($stack) {
        $current = array_pop($stack);
        $result[] = $current;
        foreach ($children[$current] ?? [] as $child) {
            $stack[] = $child;
        }
    }

    return array_values(array_unique($result));
}

function wiki_category_would_cycle( $id, $new_parent_id )
{
    if ($new_parent_id === null) {
        return false;
    }
    if ((int) $id === (int) $new_parent_id) {
        return true;
    }

    $by_id = [];
    foreach (wiki_category_rows() as $row) {
        $by_id[(int) $row["id"]] = $row;
    }

    $current = (int) $new_parent_id;
    $seen = [(int) $id => true];
    while ($current > 0 && isset($by_id[$current])) {
        if (isset($seen[$current])) {
            return true;
        }
        $seen[$current] = true;
        $current = (int) ($by_id[$current]["parent_id"] ?? 0);
    }

    return false;
}

function wiki_category_map( $row )
{
    return [
        "id" => (int) $row["id"],
        "name" => (string) $row["name"],
        "parent_id" => $row["parent_id"] === null ? null : (int) $row["parent_id"],
        "sort_order" => (int) $row["sort_order"],
        "visibility" => $row["visibility"] === "private" ? "private" : "public",
        "created_by" => $row["created_by"] === null ? null : (int) $row["created_by"],
        "created_at" => (string) $row["created_at"],
    ];
}

/**
 * 0·빈값·비정수는 "없음"(NULL)으로 본다.
 */
function wiki_parse_id( $value )
{
    if ($value === null || $value === "" || $value === 0 || $value === "0") {
        return null;
    }
    $id = filter_var($value, FILTER_VALIDATE_INT);
    return $id !== false && $id > 0 ? $id : null;
}

function wiki_next_sort_order( $parent_id )
{
    $stmt = wiki_db()->prepare(
        "SELECT COALESCE(MAX(sort_order), 0) FROM categories
         WHERE (parent_id = :parent OR (parent_id IS NULL AND :parent IS NULL))"
    );
    $stmt->bindValue(":parent", $parent_id, $parent_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->execute();
    return (int) $stmt->fetchColumn() + 1;
}

function wiki_require_category( $id )
{
    $stmt = wiki_db()->prepare("SELECT id FROM categories WHERE id = ?");
    $stmt->execute([(int) $id]);
    return (bool) $stmt->fetch();
}
