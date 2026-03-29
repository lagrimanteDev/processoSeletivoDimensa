<?php

namespace Tests\Feature\Operacoes;

use App\Models\Cliente;
use App\Models\Conveniada;
use App\Models\Operacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperacaoStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_pode_atualizar_status_com_transicao_valida_e_gravar_historico(): void
    {
        $user = User::factory()->create();
        $operacao = $this->criarOperacao('DIGITANDO', $user);

        $response = $this->actingAs($user)->patch(route('operacoes.update-status', $operacao), [
            'status' => 'PRÉ-ANÁLISE',
        ]);

        $response->assertRedirect(route('operacoes.show', $operacao));
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('operacoes', [
            'id' => $operacao->id,
            'status' => 'PRÉ-ANÁLISE',
        ]);

        $this->assertDatabaseHas('historico_status', [
            'operacao_id' => $operacao->id,
            'status_anterior' => 'DIGITANDO',
            'status_novo' => 'PRÉ-ANÁLISE',
            'user_id' => $user->id,
        ]);
    }

    public function test_usuario_pode_mudar_para_qualquer_outro_status(): void
    {
        $user = User::factory()->create();
        $operacao = $this->criarOperacao('DIGITANDO', $user);

        $response = $this->actingAs($user)->from(route('operacoes.show', $operacao))->patch(route('operacoes.update-status', $operacao), [
            'status' => 'PAGO AO CLIENTE',
        ]);

        $response->assertRedirect(route('operacoes.show', $operacao));
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('operacoes', [
            'id' => $operacao->id,
            'status' => 'PAGO AO CLIENTE',
        ]);

        $this->assertDatabaseHas('historico_status', [
            'operacao_id' => $operacao->id,
            'status_novo' => 'PAGO AO CLIENTE',
        ]);
    }

    private function criarOperacao(string $status, User $user): Operacao
    {
        $cliente = Cliente::create([
            'nome' => 'Cliente Teste',
            'cpf' => '12345678901',
            'data_nascimento' => '1990-01-01',
            'sexo' => 'M',
            'email' => 'cliente@teste.com',
        ]);

        $conveniada = Conveniada::create([
            'codigo' => 'CONV-01',
            'nome' => 'Conveniada Teste',
        ]);

        return Operacao::create([
            'codigo' => 'OP-TEST-001',
            'user_id' => $user->id,
            'cliente_id' => $cliente->id,
            'conveniada_id' => $conveniada->id,
            'valor_requerido' => 1000,
            'valor_desembolso' => 900,
            'total_juros' => 100,
            'taxa_juros' => 10,
            'taxa_multa' => 2,
            'taxa_mora' => 1,
            'status' => $status,
            'produto' => 'CREDITO',
            'data_criacao' => '2026-03-01',
            'data_pagamento' => null,
        ]);
    }
}
