// .
// .
// .

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
        })
        .catch(error => {
            this.addFlash({
                type: 'danger',
                message: trans('front.root.ajax.change_routing.error', {}, 'bridge-general', this.state.context.locale.catalogue),
            });

        })
        .finally(() => {
            // END
            window.routingBeingChanged = false;

            callback();
        })
    })
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

export function componentDidMount() {
    // .
    // .
    // .

    // cache response data
    this.cacheResponseData(this.state.context.routing.uri, this.props.data);

    // .
    // .
    // .
};

// .
// .
// .

export function preload(uri) {
    if (this.state.context.routing.office === 'back-office') return;

    if (window.routingCache !== undefined && window.routingCache[uri] !== undefined && !window.obsoleteCacheAppuser) return;

    if (window.preloading !== undefined && window.preloading[uri] === true) return;
    if (window.preloading === undefined) window.preloading = {};
    window.preloading[uri] = true;

    this.get(uri, {
        validateStatus: () => true, // accepts 404, 500, etc. and handles them in then() callback
        headers: {'X-JSON-Core': true}
    }, false, true)
        .then(response => {
            this.cacheResponseData(uri, response.data);
        })
        .finally(() => {
            window.preloading[uri] = false;
        })
}

// .
// .
// .

export function refreshRouting(scrollTopReset = false, callback = () => null) {
    if (window.refreshingRouting === true) return; // no need to add an ajax call if already being done
    window.refreshingRouting = true;
    this.clearRoutingCache(this.state.context.routing.uri); // refreshes are done independently of any cache
    this.changeRouting(this.state.context.routing.uriPathWithQuery, true, scrollTopReset, callback);
    window.refreshingRouting = false;
};

// .
// .
// .