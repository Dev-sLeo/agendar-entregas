document.addEventListener( 'DOMContentLoaded', function () {
	var el = document.getElementById( 'ae-calendario' );
	if ( ! el ) {
		return;
	}

	var modal            = document.getElementById( 'ae-modal-dia' );
	var modalConteudo    = document.getElementById( 'ae-modal-conteudo' );
	var modalTitulo      = document.getElementById( 'ae-modal-titulo' );
	var modalLista       = document.getElementById( 'ae-modal-lista' );
	var aviso            = document.getElementById( 'ae-calendario-aviso' );
	var textoSincronizado = document.getElementById( 'ae-calendario-sincronizado' );
	var botaoAtualizar   = document.getElementById( 'ae-atualizar-calendario' );

	function fecharModal() {
		modal.style.display = 'none';
	}

	document.getElementById( 'ae-modal-fechar' ).addEventListener( 'click', fecharModal );

	// Clique fora da caixa (no overlay escurecido) fecha o modal - importante
	// em dias com muitos pedidos, onde o botão "Fechar" pode ficar fora da
	// área visível e o scroll interno da lista (ver max-h/overflow-y-auto no
	// template) não deixa claro que dá pra rolar até ele.
	modal.addEventListener( 'click', function ( evento ) {
		if ( ! modalConteudo.contains( evento.target ) ) {
			fecharModal();
		}
	} );

	function mostrarAviso( mensagem, tipo ) {
		if ( ! aviso ) {
			return;
		}

		aviso.textContent = mensagem;
		aviso.className = 'mb-4 rounded-md px-4 py-3 text-sm ' + ( 'sucesso' === tipo
			? 'bg-green-50 border border-green-200 text-green-800'
			: 'bg-red-50 border border-red-200 text-red-800' );

		window.clearTimeout( mostrarAviso.timeout );
		mostrarAviso.timeout = window.setTimeout( function () {
			aviso.className = 'hidden mb-4 rounded-md px-4 py-3 text-sm';
		}, 5000 );
	}

	function atualizarTextoSincronizado( data ) {
		if ( ! textoSincronizado ) {
			return;
		}

		if ( ! data ) {
			textoSincronizado.textContent = aeCalendar.textos.nunca;
			return;
		}

		textoSincronizado.textContent = aeCalendar.textos.ultimaLabel + ': ' + new Date( data ).toLocaleString();
	}

	atualizarTextoSincronizado( aeCalendar.ultimaAtualizacao ? aeCalendar.ultimaAtualizacao.replace( ' ', 'T' ) : '' );

	var calendar = new FullCalendar.Calendar( el, {
		locale: 'pt-br',
		initialView: 'dayGridMonth',
		height: 800,
		contentHeight: 750,
		aspectRatio: 1.8,
		headerToolbar: {
			left: 'prev,next today',
			center: 'title',
			right: 'dayGridMonth,listMonth'
		},
		buttonText: {
			today: aeCalendar.textos.hoje,
			month: aeCalendar.textos.visaoMes,
			list: aeCalendar.textos.visaoLista
		},
		events: function ( info, successCallback, failureCallback ) {
			if ( botaoAtualizar ) {
				botaoAtualizar.disabled = true;
			}

			fetch( aeCalendar.restUrl + '?start=' + info.startStr + '&end=' + info.endStr, {
				headers: { 'X-WP-Nonce': aeCalendar.nonce }
			} )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( 'ae_resposta_invalida' );
					}
					return response.json();
				} )
				.then( function ( eventos ) {
					successCallback( eventos );
					mostrarAviso( aeCalendar.textos.sucesso, 'sucesso' );
					atualizarTextoSincronizado( new Date().toISOString() );
				} )
				.catch( function ( erro ) {
					failureCallback( erro );
					mostrarAviso( aeCalendar.textos.erro, 'erro' );
				} )
				.finally( function () {
					if ( botaoAtualizar ) {
						botaoAtualizar.disabled = false;
					}
				} );
		},
		eventClick: function ( info ) {
			var pedidos = info.event.extendedProps.pedidos || [];

			modalTitulo.textContent = info.event.extendedProps.turno + ' — ' + info.event.startStr;
			modalLista.innerHTML = '';

			pedidos.forEach( function ( pedido ) {
				var li = document.createElement( 'li' );
				var link = document.createElement( 'a' );
				link.href = aeCalendar.orderUrlBase + pedido.order_id;
				link.target = '_blank';
				link.textContent = '#' + pedido.order_id + ' (' + pedido.status + ')';
				li.appendChild( link );
				modalLista.appendChild( li );
			} );

			modal.style.display = 'block';
		}
	} );

	calendar.render();

	if ( botaoAtualizar ) {
		botaoAtualizar.addEventListener( 'click', function () {
			calendar.refetchEvents();
		} );
	}
} );
