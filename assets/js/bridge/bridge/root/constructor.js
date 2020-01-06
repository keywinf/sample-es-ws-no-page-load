export default function _constructor(props) {
    this.MAX_FLASH_AGE = 30; // in seconds

    const mobileProbe = document.getElementById('mobileProbe'),
        mobileProbeStyle = window.getComputedStyle(mobileProbe);

    this.lottie = {
        animationTiming: {
            t1: 0,
            t2: 3,
            t3: 3 + 12 / 25,
            t4: 7.0 + 15 / 25,
            frame: 25,
        },
        config: {
            autoplay: false,
            animationData: window.animations.login,
            renderer: 'svg',
        },
        eventListeners: [
            {
                eventName: 'DOMLoaded',
                callback: () => this.setState(produce((draft, props) => {
                    draft.animation.segments = [this.lottie.animationTiming.t3 * this.lottie.animationTiming.frame, this.lottie.animationTiming.t4 * this.lottie.animationTiming.frame];
                }))
            },
        ]
    }

    let data = _.cloneDeep(props.data);

    this.state = _.mergeWith(data, {
        animation: {
            segments: [this.lottie.animationTiming.t1 * this.lottie.animationTiming.frame, this.lottie.animationTiming.t2 * this.lottie.animationTiming.frame],
        },
        fetching: 0,
        flashes: [],
        modal: {
            // payload parameters
            component: null, // ex: App.User.Modal.Content.Login
            propsFactory: state => null, // dynamic rendering, ex: state => {prop1: value1, prop2: value2}
            title: state => null, // dynamic string
            // other parameters
            open: false,
            closable: true,
            loading: false,
        },
        menu: {
            open: false, // for touch devices
        },
        context: {
            appuser: App.Entity.Factory.create('AppUser', props.data.appuser),
            constants: data.constants,
            envs: data.envs,
            locale: data.locale,
            probe: {
                mobile: mobileProbeStyle['border-style'] === 'dotted',
                tablet: mobileProbeStyle['border-style'] === 'dashed',
                desktop: !_.includes(['dotted', 'dashed'], mobileProbeStyle['border-style']),
            },
            routing: data.routing,
            workspace: data.workspace,
        },
    }, (a, b) => b);

    delete this.state.appuser;
    delete this.state.constants;
    delete this.state.envs;
    delete this.state.locale;
    delete this.state.routing;
    delete this.state.workspace;

    window.absolute = this.absolute;
    window.addFlash = this.addFlash;
    window.all = this.all;
    window.asset = this.asset;
    window.axios = this.axios;
    window.changeRouting = this.changeRouting;
    window.clearRoutingCache = this.clearRoutingCache;
    window.gen = this.gen;
    window.get = this.get;
    window.img = this.img;
    window.post = this.post;
    window.preload = this.preload;
    window.refreshRouting = this.refreshRouting;

    window.root = this;
};