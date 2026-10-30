(function(){
                            var fieldset = document.getElementById('yoast-keys');
                            if (!fieldset) return;
                            var ajaxUrl = fieldset.getAttribute('data-ajax-url') || '';
                            var nonce = fieldset.getAttribute('data-yoast-nonce') || '';
                            var statusEl = document.getElementById('ace-yoast-ajax-status');
                            var setStatus = function(msg){
                                if (!statusEl) return;
                                statusEl.textContent = msg;
                                statusEl.style.display = msg ? 'block' : 'none';
                            };

                            var updateCounts = function(data){
                                var byId = function(id){ return document.getElementById(id); };
                                var postmeta = byId('ace-yoast-count-postmeta');
                                var termmeta = byId('ace-yoast-count-termmeta');
                                var acePost = byId('ace-yoast-count-ace-postmeta');
                                var aceTerm = byId('ace-yoast-count-ace-termmeta');
                                var postVal = (data && data.postmeta != null) ? data.postmeta : '0';
                                var termVal = (data && data.termmeta != null) ? data.termmeta : '0';
                                var acePostVal = (data && data.ace_postmeta != null) ? data.ace_postmeta : '0';
                                var aceTermVal = (data && data.ace_termmeta != null) ? data.ace_termmeta : '0';
                                if (postmeta) postmeta.textContent = postVal;
                                if (termmeta) termmeta.textContent = termVal;
                                if (acePost) acePost.textContent = acePostVal;
                                if (aceTerm) aceTerm.textContent = aceTermVal;
                            };

                            var postAjax = function(action, extra){
                                if (!ajaxUrl) {
                                    setStatus('AJAX URL missing.');
                                    return Promise.reject();
                                }
                                var formData = new FormData();
                                formData.append('action', action);
                                formData.append('nonce', nonce);
                                if (extra) {
                                    Object.keys(extra).forEach(function(key){
                                        formData.append(key, extra[key]);
                                    });
                                }
                                return fetch(ajaxUrl, {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    body: formData
                                }).then(function(resp){ return resp.json(); });
                            };

                            var refreshCounts = function(){
                                setStatus('Loading counts...');
                                return postAjax('ace_seo_get_yoast_key_counts').then(function(resp){
                                    if (resp && resp.success) {
                                        updateCounts(resp.data || {});
                                        setStatus('');
                                    } else {
                                        setStatus('Failed to load Yoast counts.');
                                    }
                                }).catch(function(){
                                    setStatus('Request failed.');
                                });
                            };

                            var refreshBtn = document.getElementById('ace-yoast-refresh');
                            if (refreshBtn) {
                                refreshBtn.addEventListener('click', function(e){
                                    e.preventDefault();
                                    refreshCounts();
                                });
                            }

                            var deleteBtn = document.getElementById('ace-yoast-delete');
                            if (deleteBtn) {
                                deleteBtn.addEventListener('click', function(e){
                                    e.preventDefault();
                                    if (!confirm('Remove all Yoast SEO meta keys from the database? This cannot be undone.')) {
                                        return;
                                    }
                                    postAjax('ace_seo_delete_yoast_keys').then(function(resp){
                                        if (resp && resp.success) {
                                            refreshCounts();
                                            alert('Yoast keys removed.');
                                        } else {
                                            alert('Failed to remove Yoast keys.');
                                        }
                                    });
                                });
                            }

                            var recreateBtn = document.getElementById('ace-yoast-recreate');
                            if (recreateBtn) {
                                recreateBtn.addEventListener('click', function(e){
                                    e.preventDefault();
                                    if (!confirm('Recreate Yoast SEO meta keys from ACE data? This will overwrite existing Yoast keys.')) {
                                        return;
                                    }
                                    postAjax('ace_seo_recreate_yoast_keys').then(function(resp){
                                        if (resp && resp.success) {
                                            refreshCounts();
                                            alert('Yoast keys recreated from ACE.');
                                        } else {
                                            alert('Failed to recreate Yoast keys.');
                                        }
                                    });
                                });
                            }

                            refreshCounts();
                        })();
