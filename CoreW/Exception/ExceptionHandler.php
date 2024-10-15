<?php


namespace CoreW\Exception;

use CoreW\Business\Common\CommonBizException;
use CoreW\Business\Common\UserException;
use support\utils\AssetHelper;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use function nl2br;

class ExceptionHandler extends \Webman\Exception\ExceptionHandler
{
    public $dontReport = [
    ];

    public $openHttpCodes = [
        400,
        401,
        422,
        201,
        403,
        413,
        500
    ];

    public $blobRoutes = [
        'admin.captcha',
        'api.captcha'
    ];

    public function report(Throwable $exception)
    {
        parent::report($exception);
    }

    public function render(Request $request, Throwable $exception): Response
    {
        list($httpCode, $error) = ExceptionUtil::getErrorAndHttpCodeFromException($exception);
        $error['data'] = null;
        if ($error['code'] === CommonBizException::USER_IP_FORBIDDEN && in_array($request->route->getName(), $this->blobRoutes)) {
            return response()->file(public_path('assets/images/default/403.png?t=' . time()));
        }

        if ($this->requestIsJson($request)) {
            if ($this->_debug) {
//                print_r(debug_backtrace(\PHP_VERSION_ID >= 50400 ? \DEBUG_BACKTRACE_IGNORE_ARGS : false));
                $error['traces'] = $this->formatExceptionTraces($exception);//explode("\n", (string)$exception);
                $error['message'] = $exception->getMessage();
            }
            $httpCode = $this->rewriteHttpCode($httpCode);
            // TODO: 刷新token

            return \Json($error)->withStatus($httpCode);
        }

        $error = $this->_debug ? nl2br((string)$exception) : 'Server internal error';
        return new Response($httpCode, [], $error);
    }

    protected function formatExceptionTraces(Throwable $exception)
    {
        $traces = [];
        $nextException = $exception;
        $source = function (Throwable $exception) {
            $file = $exception->getFile();
            $line = $exception->getLine();
            $source = '';

            if (is_file($file)) {
                $lines = file($file);
                $startLine = max(1, $line - 5);
                $endLine = min(count($lines), $line + 5);

                for ($i = $startLine; $i <= $endLine; $i++) {
                    $source .= $lines[$i - 1];
                }
            }

            return $source;
        };
        do {
            $traces[] = [
                'name' => get_class($nextException),
                'file' => $nextException->getFile(),
                'line' => $nextException->getLine(),
                'code' => $nextException->getCode(),
                'message' => $nextException->getMessage(),
                'trace' => $nextException->getTraceAsString(),
                'source' => $source($nextException)
            ];
        } while ($nextException = $nextException->getPrevious());

        return $traces;
    }

    protected function rewriteHttpCode($httpCode)
    {
        if (!$this->_debug && !in_array($httpCode, $this->openHttpCodes)) {
            $httpCode = 200;
        }

        return $httpCode;
    }

    protected function requestIsJson(Request $request)
    {
        return $request->expectsJson()
            || false !== strpos(strtolower($request->header('content-type')), 'application/json')
            || false !== strpos(strtolower($request->header('content-type')), 'text/json');
    }
}