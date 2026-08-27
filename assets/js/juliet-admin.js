/**
 * Juliet Just Mask — admin behaviors.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var formCard = document.getElementById( 'jjm-form-card' );

		document.querySelectorAll( '.jjm-toggle-form-btn' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				if ( ! formCard ) {
					return;
				}

				if ( formCard.hasAttribute( 'hidden' ) ) {
					formCard.removeAttribute( 'hidden' );
					formCard.scrollIntoView( { behavior: 'smooth', block: 'start' } );

					var firstField = formCard.querySelector( 'input[name="mask_slug"]' );

					if ( firstField ) {
						firstField.focus();
					}
				} else {
					formCard.setAttribute( 'hidden', '' );
				}
			} );
		} );

		document.querySelectorAll( '.jjm-confirm' ).forEach( function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				var message = link.getAttribute( 'data-confirm' ) || 'Are you sure?';

				if ( ! window.confirm( message ) ) {
					event.preventDefault();
				}
			} );
		} );

		// ==================================================================
		// CUSTOM SELECT SYSTEM
		// ==================================================================
		function initCustomSelects() {
			var selects = document.querySelectorAll( 'select.jjm-select:not(.jjm-select-native), select.jjm-input:not(.jjm-select-native), select.jjm-sort-select:not(.jjm-select-native)' );

			selects.forEach( function ( select ) {
				if ( select.closest( '.jjm-select-custom' ) ) {
					return; // Already initialized
				}

				// Wrap and hide native select
				var wrapper = document.createElement( 'div' );
				wrapper.className = 'jjm-select-custom';
				select.parentNode.insertBefore( wrapper, select );
				wrapper.appendChild( select );
				select.classList.add( 'jjm-select-native' );

				// Create trigger
				var trigger = document.createElement( 'div' );
				trigger.className = 'jjm-select-trigger';
				var initialText = ( select.options[ select.selectedIndex ] && select.options[ select.selectedIndex ].text ) ? select.options[ select.selectedIndex ].text : 'Select...';
				trigger.innerHTML = '<span class="jjm-selected-text">' + initialText + '</span><span class="dashicons dashicons-arrow-down-alt2"></span>';
				wrapper.appendChild( trigger );

				// Create dropdown menu
				var menu = document.createElement( 'div' );
				menu.className = 'jjm-select-dropdown';
				Array.from( select.options ).forEach( function ( option, idx ) {
					var optDiv = document.createElement( 'div' );
					optDiv.className = 'jjm-select-option' + ( select.selectedIndex === idx ? ' selected' : '' );
					optDiv.textContent = option.text;
					optDiv.dataset.value = option.value;
					optDiv.addEventListener( 'click', function ( e ) {
						e.stopPropagation();
						select.value = option.value;
						var triggerText = trigger.querySelector( '.jjm-selected-text' );
						if ( triggerText ) {
							triggerText.textContent = option.text;
						}

						menu.classList.remove( 'show' );
						trigger.classList.remove( 'active' );

						// Highlight selected
						menu.querySelectorAll( '.jjm-select-option' ).forEach( function ( o ) {
							o.classList.remove( 'selected' );
						} );
						optDiv.classList.add( 'selected' );

						// Dispatch change event to native select
						select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
					} );
					menu.appendChild( optDiv );
				} );
				wrapper.appendChild( menu );

				// Toggle logic
				trigger.addEventListener( 'click', function ( e ) {
					e.stopPropagation();

					// Close all other dropdowns
					document.querySelectorAll( '.jjm-select-dropdown.show' ).forEach( function ( openMenu ) {
						if ( openMenu !== menu ) {
							openMenu.classList.remove( 'show' );
							if ( openMenu.previousElementSibling ) {
								openMenu.previousElementSibling.classList.remove( 'active' );
							}
						}
					} );

					menu.classList.toggle( 'show' );
					trigger.classList.toggle( 'active' );
				} );
			} );
		}

		// Global click listener to close dropdowns
		document.addEventListener( 'click', function () {
			document.querySelectorAll( '.jjm-select-dropdown.show' ).forEach( function ( menu ) {
				menu.classList.remove( 'show' );
				if ( menu.previousElementSibling ) {
					menu.previousElementSibling.classList.remove( 'active' );
				}
			} );
		} );

		function syncCustomSelects() {
			document.querySelectorAll( '.jjm-select-custom' ).forEach( function ( wrapper ) {
				var select = wrapper.querySelector( 'select' );
				var triggerText = wrapper.querySelector( '.jjm-selected-text' );
				var options = wrapper.querySelectorAll( '.jjm-select-option' );

				if ( select && triggerText ) {
					var selectedOption = select.options[ select.selectedIndex ];
					triggerText.textContent = selectedOption ? selectedOption.text : 'Select...';

					options.forEach( function ( opt ) {
						opt.classList.toggle( 'selected', opt.dataset.value === select.value );
					} );
				}
			} );
		}

		initCustomSelects();

		// ==================================================================
		// SEARCH & FILTERING & SORTING
		// ==================================================================
		var cardSearch = document.getElementById( 'jjm-card-search' );
		var cardGrid   = document.getElementById( 'jjm-card-grid' );
		var noResults  = document.getElementById( 'jjm-no-results' );
		var filterBtns = document.querySelectorAll( '.jjm-filter-btn' );
		var sortSelect = document.getElementById( 'jjm-sort-select' );
		var viewBtns   = document.querySelectorAll( '.jjm-view-btn' );
		var countEl    = document.getElementById( 'jjm-showing-count' );

		var currentFilter = 'all';

		function filterCards() {
			if ( ! cardGrid ) {
				return;
			}

			var query = cardSearch ? cardSearch.value.trim().toLowerCase() : '';
			var cards = cardGrid.querySelectorAll( '.jjm-card' );
			var visibleCount = 0;

			cards.forEach( function ( card ) {
				var slug   = card.getAttribute( 'data-slug' ) || '';
				var target = card.getAttribute( 'data-target' ) || '';
				var status = card.getAttribute( 'data-status' ) || '';
				var base   = card.getAttribute( 'data-base' ) || '0';

				var matchesQuery  = ! query || slug.indexOf( query ) !== -1 || target.indexOf( query ) !== -1;
				var matchesFilter = true;

				if ( currentFilter === 'active' ) {
					matchesFilter = status === 'active';
				} else if ( currentFilter === 'inactive' ) {
					matchesFilter = status === 'inactive';
				} else if ( currentFilter === 'base' ) {
					matchesFilter = base === '1';
				}

				if ( matchesQuery && matchesFilter ) {
					card.style.display = '';
					visibleCount++;
				} else {
					card.style.display = 'none';
				}
			} );

			if ( countEl ) {
				countEl.textContent = visibleCount;
			}

			if ( noResults ) {
				if ( visibleCount === 0 && cards.length > 0 ) {
					noResults.classList.remove( 'hidden' );
				} else if ( cards.length === 0 ) {
					noResults.classList.remove( 'hidden' );
				} else {
					noResults.classList.add( 'hidden' );
				}
			}
		}

		if ( cardSearch ) {
			cardSearch.addEventListener( 'input', filterCards );
		}

		filterBtns.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				filterBtns.forEach( function ( b ) { b.classList.remove( 'active' ); } );
				btn.classList.add( 'active' );
				currentFilter = btn.getAttribute( 'data-filter' ) || 'all';
				filterCards();
			} );
		} );

		if ( sortSelect && cardGrid ) {
			sortSelect.addEventListener( 'change', function () {
				var val   = sortSelect.value;
				var cards = Array.from( cardGrid.querySelectorAll( '.jjm-card' ) );

				cards.sort( function ( a, b ) {
					if ( val === 'date-desc' ) {
						return ( parseInt( b.getAttribute( 'data-added' ), 10 ) || 0 ) - ( parseInt( a.getAttribute( 'data-added' ), 10 ) || 0 );
					} else if ( val === 'date-asc' ) {
						return ( parseInt( a.getAttribute( 'data-added' ), 10 ) || 0 ) - ( parseInt( b.getAttribute( 'data-added' ), 10 ) || 0 );
					} else if ( val === 'name-asc' ) {
						return ( a.getAttribute( 'data-slug' ) || '' ).localeCompare( b.getAttribute( 'data-slug' ) || '' );
					} else if ( val === 'name-desc' ) {
						return ( b.getAttribute( 'data-slug' ) || '' ).localeCompare( a.getAttribute( 'data-slug' ) || '' );
					} else if ( val === 'status-active' ) {
						var sa = a.getAttribute( 'data-status' ) === 'active' ? 0 : 1;
						var sb = b.getAttribute( 'data-status' ) === 'active' ? 0 : 1;
						return sa - sb;
					}
					return 0;
				} );

				cards.forEach( function ( card ) {
					cardGrid.appendChild( card );
				} );
			} );
		}

		viewBtns.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				viewBtns.forEach( function ( b ) { b.classList.remove( 'active' ); } );
				btn.classList.add( 'active' );
				var view = btn.getAttribute( 'data-view' );
				if ( view === 'list' ) {
					cardGrid.classList.remove( 'card-view' );
					cardGrid.classList.add( 'list-view' );
				} else {
					cardGrid.classList.remove( 'list-view' );
					cardGrid.classList.add( 'card-view' );
				}
			} );
		} );

		// ==================================================================
		// INSTANT STATUS TOGGLE (AJAX)
		// ==================================================================
		document.addEventListener( 'click', function ( e ) {
			var toggleLink = e.target.closest( '.jjm-switch-toggle' );
			if ( ! toggleLink ) {
				return;
			}

			if ( typeof juliet_vars === 'undefined' || ! juliet_vars.toggle_nonce ) {
				return; // allow normal link navigation if vars missing
			}

			e.preventDefault();

			var card = toggleLink.closest( '.jjm-card' );
			if ( ! card ) {
				return;
			}

			var cardIdMatch = card.id ? card.id.match( /\d+/ ) : null;
			var maskId = cardIdMatch ? parseInt( cardIdMatch[0], 10 ) : 0;

			if ( ! maskId ) {
				var href = toggleLink.getAttribute( 'href' ) || '';
				var match = href.match( /mask=(\d+)/ );
				if ( match ) {
					maskId = parseInt( match[1], 10 );
				}
			}

			if ( ! maskId ) {
				return;
			}

			var switchEl = toggleLink.querySelector( '.jjm-switch' );
			var isCurrentlyActive = switchEl ? switchEl.classList.contains( 'is-active' ) : false;
			var optimisticStatus = isCurrentlyActive ? 'inactive' : 'active';

			// Optimistic UI update
			if ( switchEl ) {
				switchEl.classList.toggle( 'is-active', ! isCurrentlyActive );
			}

			var dot = card.querySelector( '.jjm-status-dot' );
			if ( dot ) {
				dot.className = 'jjm-status-dot status-' + optimisticStatus;
			}

			var label = card.querySelector( '.jjm-status-label' );
			if ( label ) {
				label.textContent = optimisticStatus === 'active' ? 'Active Mask' : 'Inactive Mask';
			}

			card.setAttribute( 'data-status', optimisticStatus );

			var formData = new FormData();
			formData.append( 'action', 'juliet_toggle_status' );
			formData.append( 'nonce', juliet_vars.toggle_nonce );
			formData.append( 'id', maskId );

			fetch( juliet_vars.ajax_url, {
				method: 'POST',
				body: formData
			} )
				.then( function ( res ) { return res.json(); } )
				.then( function ( res ) {
					if ( res.success && res.data ) {
						var finalStatus = res.data.status;
						if ( switchEl ) {
							switchEl.classList.toggle( 'is-active', finalStatus === 'active' );
						}
						if ( dot ) {
							dot.className = 'jjm-status-dot status-' + finalStatus;
						}
						if ( label ) {
							label.textContent = res.data.label || ( finalStatus === 'active' ? 'Active Mask' : 'Inactive Mask' );
						}
						toggleLink.setAttribute( 'title', res.data.title || ( finalStatus === 'active' ? 'Deactivate mask' : 'Activate mask' ) );
						card.setAttribute( 'data-status', finalStatus );

						// Update summary chips counts
						var allCards = document.querySelectorAll( '.jjm-card' );
						var actCount = 0;
						var inactCount = 0;
						allCards.forEach( function ( c ) {
							if ( c.getAttribute( 'data-status' ) === 'active' ) {
								actCount++;
							} else {
								inactCount++;
							}
						} );

						var actEl = document.getElementById( 'jjm-active-count' );
						var inactEl = document.getElementById( 'jjm-inactive-count' );
						if ( actEl ) {
							actEl.textContent = actCount;
						}
						if ( inactEl ) {
							inactEl.textContent = inactCount;
						}

						filterCards();
					} else {
						// Revert on error
						if ( switchEl ) {
							switchEl.classList.toggle( 'is-active', isCurrentlyActive );
						}
						if ( dot ) {
							dot.className = 'jjm-status-dot status-' + ( isCurrentlyActive ? 'active' : 'inactive' );
						}
						if ( label ) {
							label.textContent = isCurrentlyActive ? 'Active Mask' : 'Inactive Mask';
						}
						card.setAttribute( 'data-status', isCurrentlyActive ? 'active' : 'inactive' );
						alert( 'Status update failed: ' + ( ( res && res.data ) || 'Unknown error' ) );
					}
				} )
				.catch( function ( err ) {
					console.error( err );
					// Revert on error
					if ( switchEl ) {
						switchEl.classList.toggle( 'is-active', isCurrentlyActive );
					}
					if ( dot ) {
						dot.className = 'jjm-status-dot status-' + ( isCurrentlyActive ? 'active' : 'inactive' );
					}
					if ( label ) {
						label.textContent = isCurrentlyActive ? 'Active Mask' : 'Inactive Mask';
					}
					card.setAttribute( 'data-status', isCurrentlyActive ? 'active' : 'inactive' );
					alert( 'Network error updating mask status.' );
				} );
		} );

		// ==================================================================
		// COPY TO CLIPBOARD
		// ==================================================================
		document.addEventListener( 'click', function ( e ) {
			var copyTarget = e.target.closest( '[data-copy]' );
			if ( ! copyTarget ) {
				return;
			}

			var text = copyTarget.getAttribute( 'data-copy' );
			if ( ! text ) {
				return;
			}

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( function () {
					var copyBtn = copyTarget.classList.contains( 'jjm-copy-btn' ) ? copyTarget : copyTarget.parentElement.querySelector( '.jjm-copy-btn' );
					if ( copyBtn ) {
						var icon = copyBtn.querySelector( '.dashicons' );
						if ( icon ) {
							var origClass = icon.className;
							icon.className = 'dashicons dashicons-yes';
							icon.style.color = '#10b981';
							setTimeout( function () {
								icon.className = origClass;
								icon.style.color = '';
							}, 1500 );
						}
					}
				} );
			}
		} );

		// ==================================================================
		// EXPORT LOGIC
		// ==================================================================
		var btnExport = document.getElementById( 'jjm-btn-export' );

		if ( btnExport && typeof juliet_vars !== 'undefined' ) {
			btnExport.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var originalHtml = btnExport.innerHTML;
				btnExport.innerHTML = '<span class="dashicons dashicons-update" style="animation:spin 2s linear infinite;"></span> <span>Exporting…</span>';
				btnExport.style.pointerEvents = 'none';

				fetch( juliet_vars.ajax_url + '?action=juliet_export_masks&nonce=' + juliet_vars.export_nonce )
					.then( function ( res ) {
						return res.json();
					} )
					.then( function ( res ) {
						if ( res.success ) {
							var dataStr = JSON.stringify( res.data, null, 2 );
							var blob    = new Blob( [ dataStr ], { type: 'application/json' } );
							var url     = URL.createObjectURL( blob );
							var a       = document.createElement( 'a' );
							a.href      = url;

							var date    = new Date();
							var months  = [ 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ];
							var day     = String( date.getDate() ).padStart( 2, '0' );
							var month   = months[ date.getMonth() ];
							var year    = date.getFullYear();
							var dateStr = day + '-' + month + '-' + year;

							a.download = 'juliet-masks-backup-' + dateStr + '.json';
							document.body.appendChild( a );
							a.click();
							document.body.removeChild( a );
							URL.revokeObjectURL( url );
						} else {
							alert( 'Export failed: ' + ( res.data || 'Unknown error' ) );
						}
					} )
					.catch( function ( err ) {
						console.error( err );
						alert( 'Export request failed. Please try again.' );
					} )
					.finally( function () {
						btnExport.innerHTML = originalHtml;
						btnExport.style.pointerEvents = 'auto';
					} );
			} );
		}

		// ==================================================================
		// IMPORT LOGIC
		// ==================================================================
		var btnImport          = document.getElementById( 'jjm-btn-import' );
		var fileInput          = document.getElementById( 'jjm-import-file' );
		var importModal        = document.getElementById( 'jjm-import-modal' );
		var btnCloseImport     = document.getElementById( 'jjm-btn-close-import' );
		var btnImportAll       = document.getElementById( 'jjm-btn-import-all' );
		var btnMerge           = document.getElementById( 'jjm-btn-merge' );
		var btnOverwrite       = document.getElementById( 'jjm-btn-overwrite' );
		var importUpdateCheck  = document.getElementById( 'jjm-import-update' );
		var logsModal          = document.getElementById( 'jjm-import-logs-modal' );
		var btnCloseLogs       = document.getElementById( 'jjm-btn-close-logs' );
		var btnDoneReload      = document.getElementById( 'jjm-btn-done-reload' );
		var statusTextEl       = document.getElementById( 'jjm-import-status-text' );
		var logsContentEl      = document.getElementById( 'jjm-import-logs-content' );

		var pendingImportData  = null;

		if ( btnImport && fileInput ) {
			btnImport.addEventListener( 'click', function () {
				fileInput.value = '';
				fileInput.click();
			} );

			fileInput.addEventListener( 'change', function ( e ) {
				var file = e.target.files[0];
				if ( ! file ) {
					return;
				}

				var reader = new FileReader();
				reader.onload = function ( ev ) {
					pendingImportData = ev.target.result;

					var importedMasks = [];
					try {
						importedMasks = JSON.parse( pendingImportData );
						if ( ! Array.isArray( importedMasks ) ) {
							importedMasks = [];
						}
					} catch ( err ) {
						alert( 'Invalid JSON file. Please upload a valid Juliet Just Mask backup.' );
						return;
					}

					var existingSlugs = new Set();
					document.querySelectorAll( '.jjm-mask-card[data-slug]' ).forEach( function ( card ) {
						var slug = card.getAttribute( 'data-slug' );
						if ( slug ) {
							existingSlugs.add( slug.toLowerCase() );
						}
					} );

					var conflicts = importedMasks.filter( function ( m ) {
						return m.mask_slug && existingSlugs.has( String( m.mask_slug ).toLowerCase() );
					} );

					var conflictCount   = conflicts.length;
					var conflictSection = document.getElementById( 'jjm-import-conflict-section' );
					var conflictCountEl = document.getElementById( 'jjm-import-conflict-count' );
					var noConflictSec   = document.getElementById( 'jjm-import-no-conflict-section' );

					if ( conflictCountEl ) {
						conflictCountEl.textContent = conflictCount;
					}

					if ( conflictCount > 0 ) {
						if ( conflictSection ) {
							conflictSection.removeAttribute( 'hidden' );
						}
						if ( noConflictSec ) {
							noConflictSec.setAttribute( 'hidden', '' );
						}
					} else {
						if ( conflictSection ) {
							conflictSection.setAttribute( 'hidden', '' );
						}
						if ( noConflictSec ) {
							noConflictSec.removeAttribute( 'hidden' );
						}
					}

					if ( importModal ) {
						importModal.removeAttribute( 'hidden' );
					}
				};
				reader.readAsText( file );
			} );
		}

		if ( btnCloseImport && importModal ) {
			btnCloseImport.addEventListener( 'click', function () {
				importModal.setAttribute( 'hidden', '' );
				pendingImportData = null;
			} );
		}

		function doImport( mode ) {
			if ( ! pendingImportData || typeof juliet_vars === 'undefined' ) {
				return;
			}

			var activeBtn = mode === 'overwrite' ? btnOverwrite : ( mode === 'merge' ? btnMerge : btnImportAll );
			if ( activeBtn ) {
				activeBtn.disabled = true;
			}

			var updateExisting = importUpdateCheck ? importUpdateCheck.checked : true;

			var formData = new FormData();
			formData.append( 'action', 'juliet_import_masks' );
			formData.append( 'nonce', juliet_vars.import_nonce );
			formData.append( 'mode', mode );
			formData.append( 'update_existing', updateExisting ? 'true' : 'false' );
			formData.append( 'data', pendingImportData );

			fetch( juliet_vars.ajax_url, {
				method: 'POST',
				body: formData
			} )
				.then( function ( res ) {
					return res.json();
				} )
				.then( function ( res ) {
					if ( importModal ) {
						importModal.setAttribute( 'hidden', '' );
					}

					if ( res.success ) {
						var summary = res.data;
						if ( statusTextEl ) {
							statusTextEl.textContent = 'Successfully processed: ' + ( summary.success_count || 0 ) + ' masks (' + ( summary.failed_count || 0 ) + ' skipped/failed).';
						}
						if ( logsContentEl ) {
							logsContentEl.textContent = ( summary.logs && summary.logs.length ) ? summary.logs.join( '\n' ) : 'Import completed with no errors.';
						}
						if ( logsModal ) {
							logsModal.removeAttribute( 'hidden' );
						}
					} else {
						alert( 'Import failed: ' + ( res.data || 'Unknown error' ) );
					}
				} )
				.catch( function ( err ) {
					console.error( err );
					alert( 'Import request error. Please try again.' );
				} )
				.finally( function () {
					if ( activeBtn ) {
						activeBtn.disabled = false;
					}
				} );
		}

		if ( btnImportAll ) {
			btnImportAll.addEventListener( 'click', function () {
				doImport( 'merge' );
			} );
		}

		if ( btnMerge ) {
			btnMerge.addEventListener( 'click', function () {
				doImport( 'merge' );
			} );
		}

		if ( btnOverwrite ) {
			btnOverwrite.addEventListener( 'click', function () {
				if ( window.confirm( 'Are you sure you want to overwrite all existing masks? This cannot be undone.' ) ) {
					doImport( 'overwrite' );
				}
			} );
		}

		if ( btnCloseLogs && logsModal ) {
			btnCloseLogs.addEventListener( 'click', function () {
				logsModal.setAttribute( 'hidden', '' );
				window.location.reload();
			} );
		}

		// ==================================================================
		// BASE TAG INFO BOX TOGGLE & MODAL
		// ==================================================================
		var baseInfoBtn = document.getElementById( 'jjm-base-info-btn' );
		var baseInfoBox = document.getElementById( 'jjm-base-info-box' );

		if ( baseInfoBtn && baseInfoBox ) {
			baseInfoBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				baseInfoBox.classList.toggle( 'hidden' );
				baseInfoBtn.classList.toggle( 'active' );
			} );
		}

		var baseModal    = document.getElementById( 'jjm-base-modal' );
		var btnCloseBase = document.getElementById( 'jjm-btn-close-base-modal' );
		var btnGotItBase = document.getElementById( 'jjm-btn-got-it-base-modal' );

		function openBaseModal( e ) {
			if ( e ) {
				e.preventDefault();
			}
			if ( baseModal ) {
				baseModal.removeAttribute( 'hidden' );
			}
		}

		function closeBaseModal( e ) {
			if ( e ) {
				e.preventDefault();
			}
			if ( baseModal ) {
				baseModal.setAttribute( 'hidden', '' );
			}
		}

		document.addEventListener( 'click', function ( e ) {
			var trigger = e.target.closest( '.jjm-open-base-modal' );
			if ( trigger ) {
				openBaseModal( e );
			}
		} );

		if ( btnCloseBase ) {
			btnCloseBase.addEventListener( 'click', closeBaseModal );
		}
		if ( btnGotItBase ) {
			btnGotItBase.addEventListener( 'click', closeBaseModal );
		}

		if ( baseModal ) {
			baseModal.addEventListener( 'click', function ( e ) {
				if ( e.target === baseModal ) {
					closeBaseModal( e );
				}
			} );
		}

		// ==================================================================
		// LIVE SLUG CONFLICT CHECKER
		// ==================================================================
		var slugInput       = document.getElementById( 'jjm-slug' );
		var conflictWarning = document.getElementById( 'jjm-slug-conflict-warning' );
		var conflictText    = document.getElementById( 'jjm-conflict-text' );
		var conflictLink    = document.getElementById( 'jjm-conflict-link' );
		var maskIdInput     = document.querySelector( 'input[name="mask_id"]' );
		var inputGroup      = slugInput ? slugInput.closest( '.jjm-input-group' ) : null;
		var conflictTimer   = null;

		function checkSlugConflict() {
			if ( ! slugInput || ! conflictWarning || typeof juliet_vars === 'undefined' || ! juliet_vars.check_nonce ) {
				return;
			}

			var slug = slugInput.value.trim().replace( /^\/+|\/+$/g, '' );
			if ( ! slug ) {
				conflictWarning.classList.add( 'hidden' );
				if ( inputGroup ) {
					inputGroup.classList.remove( 'has-conflict' );
				}
				return;
			}

			var maskId = maskIdInput ? ( parseInt( maskIdInput.value, 10 ) || 0 ) : 0;

			var formData = new FormData();
			formData.append( 'action', 'juliet_check_slug_conflict' );
			formData.append( 'nonce', juliet_vars.check_nonce );
			formData.append( 'slug', slug );
			formData.append( 'mask_id', maskId );

			fetch( juliet_vars.ajax_url, {
				method: 'POST',
				body: formData
			} )
				.then( function ( res ) { return res.json(); } )
				.then( function ( res ) {
					if ( res.success && res.data && res.data.has_conflict ) {
						conflictText.textContent = res.data.message;
						if ( res.data.url ) {
							conflictLink.href = res.data.url;
							conflictLink.textContent = ( res.data.type === 'redirect' ) ? 'View Redirect \u2192' : 'View ' + ( res.data.type === 'page' ? 'Page' : 'Post' ) + ' \u2192';
							conflictLink.classList.remove( 'hidden' );
						} else {
							conflictLink.classList.add( 'hidden' );
						}
						conflictWarning.classList.remove( 'hidden' );
						if ( inputGroup ) {
							inputGroup.classList.add( 'has-conflict' );
						}
					} else {
						conflictWarning.classList.add( 'hidden' );
						if ( inputGroup ) {
							inputGroup.classList.remove( 'has-conflict' );
						}
					}
				} )
				.catch( function ( err ) {
					console.error( 'Conflict check error:', err );
				} );
		}

		if ( slugInput ) {
			slugInput.addEventListener( 'input', function () {
				if ( conflictTimer ) {
					clearTimeout( conflictTimer );
				}
				conflictTimer = setTimeout( checkSlugConflict, 300 );
			} );

			// Check initially if editing or field is pre-filled
			if ( slugInput.value.trim() ) {
				checkSlugConflict();
			}
		}

		// ==================================================================
		// NOTICE DISMISSAL & AUTO-CLEAR
		// ==================================================================
		function dismissNotice( notice ) {
			if ( ! notice || notice.classList.contains( 'is-dismissing' ) ) {
				return;
			}
			notice.classList.add( 'is-dismissing' );
			notice.style.opacity = '0';
			notice.style.transform = 'translateY(-6px)';
			notice.style.maxHeight = notice.offsetHeight + 'px';
			notice.style.transition = 'opacity 0.3s ease, transform 0.3s ease, max-height 0.3s ease, margin 0.3s ease, padding 0.3s ease';

			setTimeout( function () {
				notice.style.maxHeight = '0';
				notice.style.paddingTop = '0';
				notice.style.paddingBottom = '0';
				notice.style.marginTop = '0';
				notice.style.marginBottom = '0';
			}, 40 );

			setTimeout( function () {
				var container = notice.parentElement;
				notice.remove();
				if ( container && container.classList.contains( 'jjm-notices-container' ) && ! container.children.length ) {
					container.remove();
				}
				// Clean query parameters from URL so page reloads don't re-trigger the notice
				if ( window.history && window.history.replaceState ) {
					var cleanUrl = window.location.href
						.replace( /[?&]juliet_msg=[^&]+/g, '' )
						.replace( /[?&]juliet_error=[^&]+/g, '' )
						.replace( /[?&]juliet_warn_conflict=[^&]+/g, '' )
						.replace( /\?&/, '?' )
						.replace( /[?&]$/, '' );
					window.history.replaceState( {}, document.title, cleanUrl );
				}
			}, 350 );
		}

		document.addEventListener( 'click', function ( e ) {
			var dismissBtn = e.target.closest( '.jjm-notice-dismiss' );
			if ( ! dismissBtn ) {
				return;
			}

			var notice = dismissBtn.closest( '.jjm-notice' );
			if ( notice ) {
				dismissNotice( notice );
			}
		} );

		// Auto-clear notices after 4 seconds (pauses on hover)
		document.querySelectorAll( '.jjm-notice' ).forEach( function ( notice ) {
			var timer = null;
			var duration = notice.classList.contains( 'jjm-notice-error' ) ? 7000 : 4000;

			function startTimer() {
				timer = setTimeout( function () {
					dismissNotice( notice );
				}, duration );
			}

			notice.addEventListener( 'mouseenter', function () {
				if ( timer ) {
					clearTimeout( timer );
					timer = null;
				}
			} );

			notice.addEventListener( 'mouseleave', function () {
				startTimer();
			} );

			startTimer();
		} );
	} );
}() );
