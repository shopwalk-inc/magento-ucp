<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Model;

use Magento\Catalog\Api\CategoryManagementInterface;
use Magento\Catalog\Api\Data\CategoryTreeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Shopwalk\Ucp\Api\CategoriesInterface;

class CategoryProvider implements CategoriesInterface
{
    public function __construct(
        private readonly CategoryManagementInterface $categoryManagement,
        private readonly StoreManagerInterface $storeManager,
        private readonly UcpEnvelope $envelope,
    ) {}

    public function getTree(): array
    {
        $rootId = (int) $this->storeManager->getStore()->getRootCategoryId();
        $tree = $this->categoryManagement->getTree($rootId);

        return [
            'categories' => $tree ? $this->flattenChildren($tree) : [],
            'ucp' => $this->envelope->build(['dev.ucp.shopping.catalog']),
        ];
    }

    /**
     * @return mixed[]
     */
    private function flattenChildren(CategoryTreeInterface $node): array
    {
        $children = [];
        foreach ($node->getChildrenData() ?? [] as $child) {
            $children[] = $this->nodeToArray($child);
        }
        return $children;
    }

    /**
     * @return mixed[]
     */
    private function nodeToArray(CategoryTreeInterface $node): array
    {
        $kids = [];
        foreach ($node->getChildrenData() ?? [] as $child) {
            $kids[] = $this->nodeToArray($child);
        }
        return [
            'id' => (int) $node->getId(),
            'name' => (string) $node->getName(),
            'slug' => $this->slugify((string) $node->getName()),
            'parent_id' => (int) $node->getParentId(),
            'product_count' => (int) $node->getProductCount(),
            'children' => $kids,
        ];
    }

    private function slugify(string $name): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name) ?? $name);
        return trim($slug, '-');
    }
}
