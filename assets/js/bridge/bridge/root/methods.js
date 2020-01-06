/**
 * Ex:
 *      - /team -> https://danim.com/team
 *      - /file.jpg -> https://cloud.com/file.jpg
 * @param src
 * @param bridge
 * @returns {string}
 */
export function absolute(src, bridge = false) {
    return (
        !src.match(/\:/) ? (
            src.match(/\./)
                ? 'https://' + root.state.context.envs.AWS_S3_URI_HOST + '/' + (bridge ? root.state.context.envs.AWS_S3_BUCKET_BRIDGE : root.state.context.envs.AWS_S3_BUCKET) + '/'
                : root.state.context.routing.uriSchemeAndHttpHost
        ) : ''
    ) + src;
};

export function addFlash(flash, ttl = 4000) {
    this.setState(produce((draft, props) => {
        flash = {...flash, id: _.uniqid()};

        _.remove(draft.flashes, f => f.message === flash.message); // replace old one

        draft.flashes = _.concat([flash], draft.flashes); // new ones at the top
    }), () => {
        if(typeof this.removeFlashTimeout === 'undefined') this.removeFlashTimeout = {};

        this.removeFlashTimeout[flash.id] = setTimeout(() => {
            this.removeFlash(flash.id);
            clearTimeout(this.removeFlashTimeout[flash.id]);
        }, ttl)
    });
};

export function all(promises, alterLoaderState = true) {
    if (alterLoaderState) this.setState(produce((draft, props) => {
        draft.fetching++;
    }));

    let promise = Promise.all(promises);

    promise
        .then(() => {
            if (alterLoaderState) this.setState(produce((draft, props) => {
                draft.fetching--;
            }));
        })
        .catch(() => {
            if (alterLoaderState) this.setState(produce((draft, props) => {
                draft.fetching--;
            }));
        });

    return promise;
};

export function asset(path) {
    return `${this.state.context.routing.uriHttpHost}/${path}`;
};

export function axios(options, alterLoaderState = true, noCache = false) {
    let baseOptions = {
        headers: {
            'Content-Type': 'application/json',
            'Cache-Control' : 'no-cache', // avoid browser caching, since some would otherwise use JSON results when user plays with history
            'X-Requested-With': 'XMLHttpRequest',
            'X-API-Secret': this.state.context.envs.API_SECRET,
            ...this.state.context.appuser ? {'X-API-Key': this.state.context.appuser.account.apiKey} : null
        },
        withCredentials: true,
    };

    if (alterLoaderState) this.setState(produce((draft, props) => {
        draft.fetching++;
    }));

    options = _.merge(baseOptions, options);

    // add nocache timestamp into query parameters, to avoid any forced browser cache
    if (noCache) options.url = this.noCacheUri(options.url);

    let promise = _axios(options);

    promise
        .then(() => {
            if (alterLoaderState) this.setState(produce((draft, props) => {
                draft.fetching--;
            }));
        })
        .catch(() => {
            if (alterLoaderState) this.setState(produce((draft, props) => {
                draft.fetching--;
            }));
        });

    return promise;
};

export function cacheResponseData(uri, data) {
    if (null === data.cacheInvalidationEvents.payload) return;

    if (window.routingCache === undefined) window.routingCache = {};
    // remove obsolete parts
    data.flashes = [];
    window.routingCache._commonCore = {data: _.cloneDeep(data), listeners: []};
    delete window.routingCache._commonCore.data.routing;
    delete window.routingCache._commonCore.data.payload;
    delete window.routingCache._commonCore.data.title;
    delete window.routingCache._commonCore.data.cacheInvalidationEvents;
    if (window.routingCache[uri] !== undefined) _.each(window.routingCache[uri].listeners, listener => document.removeEventListener(listener.event, listener.cleaner));
    window.routingCache[uri] = {data: {routing: data.routing, payload: data.payload, title: data.title}, listeners: []};
    window.obsoleteCacheAppuser = false;

    const meet = (condition, ev) => {
        let path = 'metadata._aggregate_id';

        if (condition.match(/:/)) {
            condition = _.compact(condition.split(':'));
            path = 'payload.' + condition[0];
            return condition.length > 1
                ? _.get(ev, `detail.message.${path}`) === (condition[1] === 'null' ? null : condition[1])
                : _.hasIn(ev, `detail.message.${path}`);
        }

        return _.get(ev, `detail.message.${path}`) === condition;
    }

    // cache invalidation events
    for (let type of ['appuser', 'payload']) {
        if (type === 'appuser' && window.appuserCacheListenersSet === true) continue;
        _.forOwn(data.cacheInvalidationEvents[type] || {}, (events, domain) => {
            _.forOwn(events, (conditions, event) => {
                event = 'event.' + _.snakeCase(domain) + '.' + _.snakeCase(event);
                const cleaner = ev => {
                    let cleanIt = false;

                    _.forOwn(conditions, (additionalCondition, condition) => {
                        let met = meet(condition, ev);

                        if (additionalCondition !== true) {
                            let additionalConditionMet = false;
                            additionalCondition = additionalCondition.split('|');
                            _.each(additionalCondition, c => {
                                additionalConditionMet = additionalConditionMet || meet(c, ev);
                            });
                            met = met && additionalConditionMet;
                        }

                        cleanIt = cleanIt || met;
                    });

                    if (cleanIt) {
                        if (type === 'payload') this.clearRoutingCache(uri);
                        else window.obsoleteCacheAppuser = true;
                    }
                };
                window.routingCache[type === 'payload' ? uri : '_commonCore'].listeners.push({
                    listener: document.addEventListener(event, cleaner),
                    event,
                    cleaner,
                });
            });
        });
        if (type === 'appuser') window.appuserCacheListenersSet = true;
    }
}

export function changeRouting(
    newUri,
    force = false, // force change if target is the same?
    scrollTopReset = null, // default behaviours is to arbitrate by itself
    callback = () => null // called when all's done (successfully or not)
) {
    if (_.get(window, 'routingBeingChanged') === true) return;

    newUri = this.absolute(newUri); // homogeneous is always better (ex: for routingCache)

    let oldRouting = _.cloneDeep(this.state.context.routing);

    if (`${oldRouting.uriSchemeAndHttpHost}${oldRouting.uriBaseUrl}${oldRouting.uriPathWithQuery}` === newUri && ! force) {
        this.addFlash({
            type: 'warning',
            message: trans('front.root.ajax.change_routing.already_in', {}, 'bridge-general', this.state.context.locale.catalogue),
        });

        callback();

        return;
    }

    // BEGIN
    window.routingBeingChanged = true;

    const useCache = window.routingCache !== undefined && !window.obsoleteCacheAppuser && window.routingCache[newUri] !== undefined;

    const promise = useCache
        ? new Promise(resolve => resolve({...window.routingCache._commonCore.data, ...window.routingCache[newUri].data}))
        : this.get(newUri, {
            validateStatus: () => true, // accepts 404, 500, etc. and handles them in then() callback
            headers: {'X-JSON-Core': true}
        }, true, true).then(response => response.data);

    promise
        .then(data => {
            if (data.routing.office !== oldRouting.office) { // we refresh as we have no App.Connect React class (ex: when redirected to connect office)
                window.open(data.routing.uri, '_self');
                return;
            }

            this.setState(produce((draft, props) => {
                let oldFlashes = draft.flashes;
                draft = _.mergeWith(draft, data, (a, b) => b);
                draft.context.appuser = App.Entity.Factory.create('AppUser', draft.appuser);
                draft.context.constants = draft.constants;
                draft.context.envs = draft.envs;
                draft.context.locale = draft.locale;
                draft.context.routing = draft.routing;
                draft.context.workspace = draft.workspace;
                delete draft.appuser;
                delete draft.constants;
                delete draft.envs;
                delete draft.locale;
                delete draft.routing;
                delete draft.workspace;
                draft.flashes = _.filter(oldFlashes, flash => true === _.get(flash, 'crossPage'));
                draft.menu.open = false; // reset menu rendering
            }), () => {
                // update locale cookie
                cookiesManager.setCookie(this.state.context.constants.Cookie.LOCALE, data.locale.locale, 30, this.state.context.envs.DNS_LEVEL_2 + this.state.context.envs.DNS_LEVEL_1);

                _.forEach(data.flashes, (flash, i) => {
                    setTimeout(() => {
                        this.addFlash(flash);
                    }, i * 200);
                });

                // update history
                if (oldRouting.uriPathWithQuery !== data.routing.uriPathWithQuery) {
                    hist.push(data.routing.uriPathWithQuery);

                    if (scrollTopReset !== false) window.scrollTo(0, 0); // if not explicitly refused, reset scroll top

                    if (window.menu) menu.setState(produce((draft, props) => {
                        _.each(Object.keys(draft.dropdown), key => {
                            draft.dropdown[key] = false;
                        });
                    }));
                } else {
                    if (scrollTopReset) window.scrollTo(0, 0); // if explicitly wished, reset scroll top
                }

                if (!useCache) this.cacheResponseData(newUri, data);

                // set new <html> class
                document.getElementsByTagName('html')[0].classList.remove(`html/routed/${_.camelCase(oldRouting.office)}`, `html/routed/${_.camelCase(oldRouting.office)}/${oldRouting.route}`);
                document.getElementsByTagName('html')[0].classList.add(`html/routed/${_.camelCase(this.state.context.routing.office)}`, `html/routed/${_.camelCase(this.state.context.routing.office)}/${this.state.context.routing.route}`);

                // set title
                document.title = `${data.appuser && data.appuser.profile.newNotifications.count > 0 ? `(${data.appuser.profile.newNotifications.count}) ` : ''}${this.state.context.envs.APP_ENV !== 'prod' ? `${_.upperCase(this.state.context.envs.APP_ENV)} • ` : ''}${data.title ? `${trans(data.title.id, data.title.parameters, data.title.domain, this.state.context.locale.catalogue)} • ` : ''}${this.state.context.envs[`OFFICE_${_.upperCase(this.state.context.envs.OFFICE).replace(' ', '_')}_TITLE`]}`;
            });

            // END
            window.routingBeingChanged = false;

            callback();
        })
        .catch(error => {
            this.addFlash({
                type: 'danger',
                message: trans('front.root.ajax.change_routing.error', {}, 'bridge-general', this.state.context.locale.catalogue),
            });

            // END
            window.routingBeingChanged = false;

            callback();
        });
};

export function clearRoutingCache(keys = null) {
    if (_.isString(keys)) keys = [keys];

    const removeCallback = listener => document.removeEventListener(listener.event, listener.cleaner);

    if (!keys) {
        _.each(window.routingCache, cache => _.each(cache.listeners, removeCallback));
        window.routingCache = {};
    } else if (window.routingCache !== undefined) _.each(keys, key => {
        if (window.routingCache[key] !== undefined) {
            _.each(window.routingCache[key].listeners, removeCallback);
            delete window.routingCache[key];
        }
    });
}

export function componentDidCatch(error, info) {
    this.addFlash({
        type: 'danger',
        message: trans('front.root.ui.error', {}, 'bridge-general', this.state.context.locale.catalogue)
    });
};

export function componentDidMount() {
    // locale cookie
    cookiesManager.setCookie(this.state.context.constants.Cookie.LOCALE, this.state.context.locale.locale, 30, this.state.context.envs.DNS_LEVEL_2 + this.state.context.envs.DNS_LEVEL_1);

    _.forEach(this.subscribedToEvents(), event => document.addEventListener(event, this.handleEvent));

    // flashes
    _.forEach(this.props.data.flashes, (flash, i) => {
        const nowTimestamp = new Date().getTime() / 1000;
        if (nowTimestamp - flash.timestamp <= this.MAX_FLASH_AGE) this.addFlash(flash); // add flash only if recent
    });

    window.addEventListener(
        'resize',
        _.throttle(() => {
            const mobileProbe = document.getElementById('mobileProbe'),
                mobileProbeStyle = window.getComputedStyle(mobileProbe);

            this.setState(produce((draft, props) => {
                draft.context.probe = {
                    mobile: mobileProbeStyle['border-style'] === 'dotted',
                    tablet: mobileProbeStyle['border-style'] === 'dashed',
                    desktop: !_.includes(['dotted', 'dashed'], mobileProbeStyle['border-style']),
                    // these are quite useful for optimized pure components to update even if their props are not changed (assuming they're context-aware)
                    width: window.innerWidth,
                    height: window.innerHeight,
                };
            }));
        }, 100)
    );

    // useful for floating effects to remain when page is reloaded under a non-zero scrollTop
    epsilonScroll();

    // cache response data
    this.cacheResponseData(this.state.context.routing.uri, this.props.data);

    // hist
    //      temporarily deactivate browser auto scroll, that is handled by app itself (history is a cross-page variable)
    if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
    //      init
    window.hist = createBrowserHistory({
        basename: this.state.context.routing.uriBase,  // The base URL of the app (see below)
        forceRefresh: false,                    // Set true to force full page refreshes on push
        keyLength: 6,                           // The length of location.key
        // A function to use to confirm navigation with the user (see below)
        getUserConfirmation: (message, callback) => callback(window.confirm(message))
    });
    //      listen
    hist.listen((location, action) => {
        let uriPathWithQuery = `${location.pathname}${location.search}${location.hash}`;

        if (action === 'POP') this.changeRouting(uriPathWithQuery);
    });

    // web socket
    window.ws = io(`${this.state.context.envs.WS_INIT_PROTOCOL}://${this.state.context.envs.WS_HOST_DNS_LEVEL_3}${this.state.context.envs.DNS_LEVEL_2}${this.state.context.envs.DNS_LEVEL_1}${this.state.context.envs.DNS_DEFAULT_PORT}${_.get(this.state, 'context.appuser.account.apiKey') ? `?key=${encodeURIComponent(this.state.context.appuser.account.apiKey)}&secret=${encodeURIComponent(this.state.context.envs.WS_SECRET)}` : ''}`);
    ws.on(this.state.context.envs.WS_EVENT_BUS_DISPATCH_TOPIC, data => {
        data = JSON.parse(data);
        data = _.merge(data, {
            aggregate_type: _.snakeCase(_.last(_.split(data.message.metadata._aggregate_type, '\\'))), // User => user
            aggregate_id: data.message.metadata._aggregate_id,
            event: _.snakeCase(_.last(_.split(data.message.message_name, '\\'))), // App\domain\User\Event\UserProfileWasChanged => user_profile_was_changed
        });

        cl(data);

        document.dispatchEvent(new CustomEvent(`event.${data.aggregate_type}.${data.event}`, {detail: data}));
    });
    ws.on('connect', () => cl('WebSocket open'));
    ws.on('disconnect', () => cl('WebSocket closed'));

    // modal by query string (ex: modal=App.User.Modal.Content.User
    // no need to clean user input, no risk
    const modal = _.get(this.state, 'context.routing.query.modal');
    if (modal) {
        const modalProps = _.get(this.state, 'context.routing.query.modalProps');
        window.modal.set(_.get(window, modal), null, modalProps ? JSON.parse(modalProps) : null);
    }

    // service worker
    // Listen the "add to home screen" event and show the dialog on mobile
    window.addEventListener('beforeinstallprompt', ev => {
        ev.preventDefault();
    //     window.onscroll = () => ev.prompt();
    //     // Wait for the user to respond to the prompt
    //     ev.userChoice.then(res => {
    //         if (res.outcome === 'accepted') console.log('User accepted the A2HS prompt');
    //         else console.log('User dismissed the A2HS prompt');
    //     });
    });
    window.swBoot();
};

export function componentDidUpdate(prevProps, prevState) {
    // let websocket know if user anonymity switched
    let newId = _.get(this.state, 'context.appuser.id', null),
        oldId = _.get(prevState, 'context.appuser.id', null);
    if (newId !== oldId) {
        if (newId) ws.emit('anonymous_off', this.state.context.appuser.account.apiKey);
        else ws.emit('anonymous_on');
    }

    // update favicon when there are new notifications or some are read/removed
    let links = document.querySelectorAll('link[data-icon]');
    let newTitle = document.title.replace(/^\(\d+\)\s/, '');
    if (this.state.context.appuser) {
        if (this.state.context.appuser.profile.newNotifications.count) {
            for (let link of links)
                if (!link.getAttribute('href').match(/-red\./))
                    link.setAttribute('href', link.getAttribute('href').replace(/\./, '-red.'));
            newTitle = `(${this.state.context.appuser.profile.newNotifications.count}) ${newTitle}`;
        } else {
            for (let link of links) link.setAttribute('href', link.getAttribute('href').replace(/-red\./, '.'));
        }
    }
    document.title = newTitle;
};

export function componentWillUnmount() {
    _.forEach(this.subscribedToEvents(), event => document.removeEventListener(event, this.handleEvent));
};

export function gen(route, parameters = {}) {
    parameters = _.merge({ // default parameters
        ...route.match(/^connect\:\:/) ? {dns_level_4: this.state.context.envs.DNS_LEVEL_4} : null,
        dns_level_2: this.state.context.envs.DNS_LEVEL_2,
    }, parameters);

    let ans = Routing.generate(route, parameters);

    if (this.state.context.envs.DNS_DEFAULT_PORT) ans = ans.replace(this.state.context.envs.DNS_LEVEL_2 + this.state.context.envs.DNS_LEVEL_1, this.state.context.envs.DNS_LEVEL_2 + this.state.context.envs.DNS_LEVEL_1 + this.state.context.envs.DNS_DEFAULT_PORT);

    return ans;
};

export function get(url, options, alterLoaderState = true, noCache = false) {
    return this.axios(_.merge(options, {url}), alterLoaderState, noCache);
};

export function handleEvent(ev) {
    switch (`${ev.detail.aggregate_type}.${ev.detail.event}`) {
        case 'user.user_account_was_changed':
            // if (_.get(ev, 'detail.message.payload.patch.last_login')) {
            //     if (this.state.modal.component && this.state.modal.component.path() === App.User.Modal.Content.Login.path() && this.state.modal.open) {
            //         ws.emit('anonymous_off', this.state.context.appuser.account.apiKey);
            //
            //         modal.close();
            //
            //         root.refreshRouting();
            //     }
            // }
            //
            // if (_.get(ev, 'detail.message.payload.patch.last_logout')) {
            //     ws.emit('anonymous_on');
            //
            //     modal.set(App.User.Modal.Content.Login, null, null, false, () => modal.open(false));
            // }

            break;
    }
};

export function handleFlashClick(ev) {
    const flashId = ev.currentTarget.getAttribute('data-flash-id');
    clearTimeout(this.removeFlashTimeout[flashId]);
    this.removeFlash(flashId);
};

export function handleMenuToggle() {
    this.setState(produce((draft, props) => {
        draft.menu.open = !draft.menu.open;
    }));
}

export function img(path, filter = null) {
    if (! path) return null;
    if (! filter || this.state.context.envs.IMAGES_OPTIMIZATION === '0') return absolute(path);
    return this.gen('liip_imagine_filter', {path, filter});
};

export function noCacheUri(uri) {
    if (!uri.match(/\?/)) uri += '?';
    else uri += '&';
    uri += 'nc=' + new Date().getTime();

    return uri;
}

export function post(url, options, alterLoaderState = true, noCache = false) {
    return this.axios(_.merge(options, {url, method: 'post'}), alterLoaderState, noCache);
};

export function preload(uri) {
    if (this.state.context.routing.office === 'back-office') return;

    if (window.routingCache !== undefined && window.routingCache[uri] !== undefined && !window.obsoleteCacheAppuser) return;

    if (window.preloading !== undefined && window.preloading[uri] === true) return;
    if (window.preloading === undefined) window.preloading = {};
    window.preloading[uri] = true;

    this.get(uri, {
        validateStatus: () => true, // accepts 404, 500, etc. and handles them in then() callback
        headers: {'X-JSON-Core': true}
    }, false, true).then(response => {
        window.preloading[uri] = false;
        this.cacheResponseData(uri, response.data);
    });
}

export function purgeFlashes() {
    _.each(this.state.flashes, flash => {
        this.removeFlash(flash.id);
    });
};

export function refreshRouting(scrollTopReset = false, callback = () => null) {
    if (window.refreshingRouting === true) return; // no need to add an ajax call if already being done
    window.refreshingRouting = true;
    this.clearRoutingCache(this.state.context.routing.uri); // refresh are done independently of any cache
    this.changeRouting(this.state.context.routing.uriPathWithQuery, true, scrollTopReset, callback);
    window.refreshingRouting = false;
};

export function removeFlash(flashId) {
    this.setState(produce((draft, props) => {
        _.remove(draft.flashes, function(flash) {
            return flash.id === flashId;
        });
    }));
};

export function subscribedToEvents() {
    return [
        'event.user.user_account_was_changed',
    ];
};