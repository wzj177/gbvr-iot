<?php

namespace CoreW\Traits;

use support\Request;

trait RequestAndResponseTrait
{
    /**
     *
     * @param null|array $data
     * @param string $message
     * @param int $statusCode
     * @return \support\Response
     */
    protected function createSuccessJsonResponse($data = [], $message = 'success', $statusCode = 200)
    {
        return json(['code' => self::BIS_SUCCESS_CODE, 'data' => $data, 'message' => $message], JSON_UNESCAPED_UNICODE, $statusCode);
    }

    /**
     * 标准错误返回
     *
     *
     * @param string $message
     * @param int $statusCode
     * @param int $code
     *
     * @return \support\Response
     * @deprecated
     */
    protected function createFailJsonResponse($message = 'failed', $statusCode = 200, $code = -1)
    {
        return json(['code' => $code, 'data' => null, 'message' => $message], JSON_UNESCAPED_UNICODE, $statusCode);
    }

    /**
     * 标准错误返回
     *
     * @param string $message
     * @param null $data
     * @param int $code
     * @param int $statusCode
     *
     * @return \support\Response
     */
    protected function createErrorJsonResponse($message = 'error', $data = null, $code = -1, $statusCode = 200)
    {
        $response = json(['code' => $code, 'data' => $data, 'message' => $message], JSON_UNESCAPED_UNICODE);
        $response->withStatus($statusCode);
        return $response;
    }

    /**
     * 获取分页参数
     * @param Request $request
     * @param string $pageKey 从1开始
     * @param string $limitKey
     * @return array
     */
    protected function getOffsetAndLimit(Request $request, string $pageKey = 'page', string $limitKey = 'page_size'): array
    {
        if (strtolower($request->method()) === 'get') {
//            $offset = $request->get($offsetKey, self::DEFAULT_PAGING_OFFSET);
            $page = $request->get($pageKey, self::DEFAULT_PAGING_PAGE);
            $limit = $request->get($limitKey, self::DEFAULT_PAGING_LIMIT);
        } else {
            $body = $request->post();
            $post = empty($body) ? json_decode($request->rawBody(), true) : $body;
//            $offset = $post[$offsetKey] ?? self::DEFAULT_PAGING_OFFSET;
            $page = $post[$pageKey] ?? self::DEFAULT_PAGING_PAGE;
            $limit = $post[$limitKey] ?? self::DEFAULT_PAGING_LIMIT;
        }

        return [$page < 1 ? 0 : (int)$page - 1, min($limit, self::MAX_PAGING_LIMIT)];
    }

    /**
     * @param Request $request
     * @return array
     */
    protected function getSort(Request $request)
    {
        $sortStr = $request->get('sort');

        if ($sortStr) {
            $explodeSort = explode(',', $sortStr);

            $sort = [];
            foreach ($explodeSort as $part) {
                $prefix = substr($part, 0, 1);
                $field = str_replace(self::PREFIX_SORT_DESC, '', $part);
                if (self::PREFIX_SORT_DESC == $prefix) {
                    $sort[$field] = 'DESC';
                } else {
                    $sort[$field] = 'ASC';
                }
            }

            return $sort;
        }

        return [];
    }

    /**
     * @param $objects
     * @param $total
     * @param $offset
     * @param $limit
     * @return array
     */
    protected function makePagingObject($objects, $total, $offset, $limit)
    {
        return [
            'list' => $objects,
            'paging' => [
                'total' => $total,
                'offset' => $offset,
                'limit' => $limit,
            ],
        ];
    }
}