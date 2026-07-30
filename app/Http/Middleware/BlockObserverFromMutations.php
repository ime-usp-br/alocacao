<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Garante que o perfil "Observador" jamais dispare requisicoes que alterem
 * estado (POST/PUT/PATCH/DELETE). Trata-se de uma rede de seguranca em
 * profundidade: a protecao principal fica nos cheques de role de cada
 * controller, mas este middleware impede, de forma centralizada, que qualquer
 * rota de escrita seja acionada por um Observador - incluindo rotas novas que
 * venham a ser adicionadas sem o cheque de role adequado.
 *
 * Excecao: a rota de "logout" (POST) permanece liberada para que o Observador
 * consiga encerrar a sessao.
 */
class BlockObserverFromMutations
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if ($user && $user->hasRole('Observador') && ! $request->isMethodSafe() && ! $request->is('logout')) {
            abort(403, 'O perfil Observador possui acesso somente leitura e não pode alterar dados.');
        }

        return $next($request);
    }
}