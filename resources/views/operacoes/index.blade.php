<x-app-layout>
	<x-slot name="header">
		<div class="flex items-center justify-between">
			<h2 class="font-semibold text-xl text-gray-800 leading-tight">
				Operações
			</h2>
		</div>
	</x-slot>

	<div class="py-8">
		<div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
			@if (session('status'))
				<div class="bg-green-100 border border-green-300 text-green-800 px-4 py-3 rounded">
					{{ session('status') }}
				</div>
			@endif

			@if ($errors->any())
				<div class="bg-red-100 border border-red-300 text-red-800 px-4 py-3 rounded">
					<ul class="list-disc list-inside">
						@foreach ($errors->all() as $error)
							<li>{{ $error }}</li>
						@endforeach
					</ul>
				</div>
			@endif

			<div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
				<h3 class="text-lg font-medium text-gray-900 mb-4">Importar planilha</h3>
				<form id="import-form" action="{{ route('operacoes.import') }}" method="POST" enctype="multipart/form-data" class="flex items-center gap-3">
					@csrf
					<input type="file" name="arquivo" accept=".xlsx,.xls,.csv" class="border-gray-300 rounded-md shadow-sm" required>
					<x-primary-button id="import-button">Importar</x-primary-button>
				</form>
				<p id="import-warning" class="hidden mt-3 text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded px-3 py-2">
					Importação iniciada. Este processo pode levar alguns minutos.
				</p>
			</div>

			<div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
				<form method="GET" action="{{ route('operacoes.index') }}" class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
					<div>
						<label class="block text-sm text-gray-700 mb-1">Código</label>
						<input type="text" name="codigo" value="{{ request('codigo') }}" class="w-full border-gray-300 rounded-md shadow-sm">
					</div>

					<div>
						<label class="block text-sm text-gray-700 mb-1">Cliente (nome ou CPF)</label>
						<input type="text" name="cliente" value="{{ request('cliente') }}" class="w-full border-gray-300 rounded-md shadow-sm">
					</div>

					<div>
						<label class="block text-sm text-gray-700 mb-1">Status</label>
						<select name="status" class="w-full border-gray-300 rounded-md shadow-sm">
							<option value="">Todos</option>
							@foreach ($statuses as $status)
								<option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
							@endforeach
						</select>
					</div>

					<div class="flex items-end gap-2">
						<x-primary-button>Filtrar</x-primary-button>
						<a href="{{ route('operacoes.index') }}" class="inline-flex items-center px-4 py-2 bg-gray-100 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-200">
							Limpar
						</a>
					</div>
				</form>

				<div class="overflow-x-auto">
					<table class="min-w-full divide-y divide-gray-200 text-sm">
						<thead class="bg-gray-50">
							<tr>
								<th class="px-3 py-2 text-left font-semibold text-gray-700">Código</th>
								<th class="px-3 py-2 text-left font-semibold text-gray-700">Cliente</th>
								<th class="px-3 py-2 text-left font-semibold text-gray-700">CPF</th>
								<th class="px-3 py-2 text-left font-semibold text-gray-700">Conveniada</th>
								<th class="px-3 py-2 text-left font-semibold text-gray-700">Valor da operação</th>
								<th class="px-3 py-2 text-left font-semibold text-gray-700">Status</th>
								<th class="px-3 py-2 text-left font-semibold text-gray-700">Produto</th>
								<th class="px-3 py-2 text-left font-semibold text-gray-700">Ações</th>
							</tr>
						</thead>
						<tbody class="divide-y divide-gray-100">
							@forelse ($operacoes as $operacao)
								<tr>
									<td class="px-3 py-2">{{ $operacao->codigo }}</td>
									<td class="px-3 py-2">{{ $operacao->cliente?->nome }}</td>
									<td class="px-3 py-2">{{ $operacao->cliente?->cpf }}</td>
									<td class="px-3 py-2">{{ $operacao->conveniada?->nome }}</td>
									<td class="px-3 py-2">R$ {{ number_format((float) ($operacao->valor_desembolso ?: $operacao->valor_requerido), 2, ',', '.') }}</td>
									<td class="px-3 py-2">{{ $operacao->status }}</td>
									<td class="px-3 py-2">{{ $operacao->produto }}</td>
									<td class="px-3 py-2">
										<a href="{{ route('operacoes.show', $operacao) }}" class="text-indigo-600 hover:text-indigo-800">Detalhes</a>
									</td>
								</tr>
							@empty
								<tr>
									<td colspan="8" class="px-3 py-6 text-center text-gray-500">Nenhuma operação encontrada.</td>
								</tr>
							@endforelse
						</tbody>
					</table>
				</div>

				<div class="mt-4">
					{{ $operacoes->links() }}
				</div>
			</div>
		</div>
	</div>

	<script>
		document.addEventListener('DOMContentLoaded', function () {
			const form = document.getElementById('import-form');
			const warning = document.getElementById('import-warning');
			const button = document.getElementById('import-button');

			if (!form || !warning || !button) {
				return;
			}

			form.addEventListener('submit', function () {
				warning.classList.remove('hidden');
				button.disabled = true;
				button.textContent = 'Importando...';
			});
		});
	</script>
</x-app-layout>
