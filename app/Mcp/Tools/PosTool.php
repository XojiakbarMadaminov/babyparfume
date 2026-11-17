<?php

namespace App\Mcp\Tools;

use Throwable;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

abstract class PosTool extends Tool
{
    public function handle(Request $request): Response
    {
        try {
            return Response::json($this->execute($request));
        } catch (ValidationException $exception) {
            return Response::error(json_encode($exception->errors(), JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        } catch (AuthorizationException) {
            return Response::error('Bu amalga ruxsat yo‘q.');
        } catch (ModelNotFoundException) {
            return Response::error('Tanlangan do‘konda yozuv topilmadi.');
        } catch (HttpExceptionInterface $exception) {
            return Response::error('Amal bajarilmadi (' . $exception->getStatusCode() . '). Ruxsat, do‘kon va amal holatini tekshiring.');
        } catch (Throwable $exception) {
            report($exception);

            return Response::error('Server xatosi. Amal natijasini tekshiring, qayta yozishdan oldin request_id ni saqlang.');
        }
    }

    /** @return array<string, mixed> */
    abstract protected function execute(Request $request): array;
}
