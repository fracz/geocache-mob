<?php
require_once __DIR__ . '/recaptcha-verify.php';
$config = require __DIR__ . '/../../config/db.php';

$conn = new PDO("mysql:host=$config[DB_HOST];dbname=$config[DB_NAME];port=$config[DB_PORT];charset=utf8mb4", $config['DB_USER'], $config['DB_PASSWORD']);
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$mobCache = [];

$isPost = strtolower($_SERVER['REQUEST_METHOD']) === 'post';
$errors = [];
$authcode = '';
$edit = '';
$savedEdit = false;

if (preg_match('#^GC[0-9A-Z]{4,5}$#', $_GET['edit'])) {
    $edit = $_GET['edit'];
}

if ($isPost) {
    $captcha = $_POST['g-recaptcha-response'] ?? '';
    if (!isCaptchaValid($captcha)) {
        $errors['general'] = 'Invalid captcha.';
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $mobCache = $_POST;
        $mobCache['code'] = strtoupper($mobCache['code'] ?? '');
        if (!preg_match('#^GC[0-9A-Z]{4,5}$#', $mobCache['code'])) {
            $errors['code'] = 'Invalid GC code';
        }
        $mobCache['coords'] = trim($mobCache['coords'] ?? '');
        if (!$mobCache['coords'] || !preg_match('#^[NS] \d\d° [0-5]\d.\d\d\d [EW] [01]\d\d° [0-5]\d.\d\d\d$#', $mobCache['coords'])) {
            $errors['coords'] = 'Invalid coordinates.';
        }
        $mobCache['radius'] = max(10, min(intval($mobCache['radius'] ?? 50), 150));
        $mobCache['minAttendees'] = max(1, min(intval($mobCache['minAttendees'] ?? 5), 20));
        $mobCache['finalCoords'] = trim($mobCache['finalCoords'] ?? '');
        if (!$mobCache['finalCoords'] || !preg_match('#^[NS] \d\d° [0-5]\d.\d\d\d [EW] [01]\d\d° [0-5]\d.\d\d\d$#', $mobCache['finalCoords'])) {
            $errors['finalCoords'] = 'Invalid coordinates.';
        }
        $mobCache['finalHint'] = substr(trim($mobCache['finalHint'] ?? ''), 0, 250);

        if (!$errors) {
            try {
                if ($mobCache['authcode']) {
                    $sql = "UPDATE mob_cache SET coords=?, radius=?, min_attendees=?, final_coords=?, final_hint=? WHERE code=? AND authcode=?";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$mobCache['coords'], $mobCache['radius'], $mobCache['minAttendees'],
                        $mobCache['finalCoords'], $mobCache['finalHint'], $mobCache['code'], sha1('mobCache' . $mobCache['authcode'])]);
                    $savedEdit = true;
                    $mobCache = [];
                } else {
                    $authcode = substr(bin2hex(openssl_random_pseudo_bytes(16)), 0, 15);
                    $sql = "INSERT INTO mob_cache(code, coords, radius, min_attendees, final_coords, final_hint, authcode) VALUES(?,?,?,?,?,?,?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$mobCache['code'], $mobCache['coords'], $mobCache['radius'], $mobCache['minAttendees'],
                        $mobCache['finalCoords'], $mobCache['finalHint'], sha1('mobCache' . $authcode)]);
                }
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'Duplicate entry')) {
                    $errors['general'] = 'This MOB already exists. Maybe you want to <a href="/mob/?edit=' . $mobCache['code'] . '">edit it</a>?';
                } else {
                    $errors['general'] = 'Some error occured.';
                }
                $authcode = '';
            }
        }
    } elseif ($action === 'edit' && !$errors) {
        $stmt = $conn->prepare("SELECT code, coords, radius, min_attendees minAttendees, final_coords finalCoords, final_hint finalHint FROM mob_cache WHERE code=? AND authcode=?");
        $stmt->execute([$edit, sha1('mobCache' . $_POST['authcode'] ?? '')]);
        $mobCache = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$mobCache) {
            $errors['general'] = 'Invalid auth code.';
            $mobCache = [];
        }
    }
}
?><!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MOB Geocache</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@0.9.4/css/bulma.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/vue-slider-component@3.2.5/theme/default.css">
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
</head>
<body>
<section class="section" id="app">
    <div class="container">
        <h1 class="title">
            MOB Geocaches
        </h1>
        <?php if ($errors['general']): ?>
            <article class="message is-danger">
                <div class="message-header">
                    <p>Problem!</p>
                </div>
                <div class="message-body">
                    <?= $errors['general'] ?>
                </div>
            </article>
        <?php endif; ?>
        <?php if ($edit && !$mobCache): ?>
            <h2 class="title is-size-4">Edit MOB <?= $edit ?></h2>
            <?php if ($savedEdit): ?>
                <article class="message is-success">
                    <div class="message-header">
                        <p>Your MOB cache has been updated.</p>
                    </div>
                    <div class="message-body">
                        The link and the authcode for the MOB did not change. The link is:
                        <div class="is-size-3 my-3">
                            <code>https://geocaching.fracz.com/mob/<?= $edit ?></code>
                        </div>
                        <p class="my-2">
                            Everything done? Now you can <a href="/mob/">create another MOB</a>,
                            <a href="https://coord.info/<?= $edit ?>">visit your geocache listing</a> or
                            <a href="/mob/<?= $edit ?>">visit your MOB page</a>.
                        </p>
                    </div>
                </article>
            <?php else: ?>
                <p class="subtitle">
                    After creation of the MOB for the first time, you received an one-time authcode.
                    Paste it here to be able to edit your MOB.
                </p>
                <p class="subtitle">
                    Lost your authkey?
                    <a href="https://www.geocaching.com/account/messagecenter?recipientId=49369c87-1a23-4cd6-a054-3c76cf2399f6&gcCode=<?= $edit ?>">
                        Contact me
                    </a>
                    from the cache owner account.
                </p>
                <form action="" method="post" ref="protectedForm">
                    <input type="hidden" name="action" value="edit">
                    <label class="label">Authcode</label>
                    <div class="field has-addons">
                        <div class="control is-expanded">
                            <input class="input is-large" type="password" placeholder="Your authcode" maxlength="20"
                                   name="authcode">
                        </div>
                        <div class="control">
                            <button type="submit" class="button is-info is-large" id="submitbutton">
                                Edit
                            </button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        <?php elseif ($authcode): ?>
            <article class="message is-success">
                <div class="message-header">
                    <p>Your MOB cache has been created.</p>
                </div>
                <div class="message-body">
                    You are ready to set up your MOB cache. Your link is:
                    <div class="is-size-3 my-3">
                        <code>https://geocaching.fracz.com/mob/<?= $mobCache['code'] ?></code>
                    </div>
                    <p class="my-2">
                        Place the link in the listing of your cache, describe what the cache is about and you are good
                        to go!
                    </p>
                    <p>
                        <strong>IMPORTANT:</strong> In order to be able to edit the details of this MOB cache (i.e.
                        change the coordinates or settings), save the auth code for this MOB cache in a safe place.
                        A private note for your cache is a good place for that. You will need it when you will want
                        to change anything regarding this MOB. Once you leave this page, you will not be able to see
                        it again!
                    </p>
                    <div class="is-size-3 my-3">
                        <code><?= $authcode ?></code>
                    </div>
                    <p class="my-2">
                        Everything done? Now you can <a href="/mob/">create another MOB</a>,
                        <a href="https://coord.info/<?= $mobCache['code'] ?>">visit your geocache listing</a> or
                        <a href="/mob/<?= $mobCache['code'] ?>">visit your new MOB page</a>.
                    </p>
                </div>
            </article>
        <?php else: ?>
            <p class="subtitle">
                MOB Geocaches aim to gather certain number of people at specific place and ask them to open the same URL
                with their mobile devices.
                As soon as the system detects the required number of connected devices, it will display the final
                geocache
                coordinates and an optional hint.
            </p>

            <div class="columns is-centered">
                <?php if (!$mobCache): ?>
                    <div class="column">
                        <h2 class="title is-size-4">Find an existing MOB</h2>
                        <p class="subtitle">
                            If you want to discover coordinates of an existing MOB geocache, use the URL provided in the
                            geocache listing or search for the desired page with its GC code.
                        </p>
                        <form action="mob.php" method="get">
                            <div class="field has-addons">
                                <div class="control is-expanded">
                                    <input class="input is-large" type="text" placeholder="GCXXXXX" maxlength="7" name="code">
                                </div>
                                <div class="control">
                                    <button type="submit" class="button is-info is-large">
                                        Search
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
                <div class="column is-half-desktop">
                    <?php if ($edit): ?>
                        <h2 class="title is-size-4">Edit your MOB geocache <?= $edit ?></h2>
                    <?php else: ?>
                        <h2 class="title is-size-4">Create your MOB geocache</h2>
                        <p class="subtitle">
                            Firstly, you need to create your geocache listing in order to obtain your GC code. Do not
                            publish
                            your cache yet. Fill in the form to set up your MOB.
                        </p>
                    <?php endif; ?>

                    <form method="post" action="" ref="protectedForm">
                        <input type="hidden" name="action" value="save">
                        <?php if ($_POST['authcode'] ?? ''): ?>
                            <input type="hidden" name="authcode" value="<?= $_POST['authcode'] ?>">
                        <?php endif; ?>
                        <div class="field">
                            <label class="label">GC Code</label>
                            <div class="control">
                                <input class="input" type="text" placeholder="GCXXXXX" maxlength="7" name="code"
                                       v-model="mobCache.code" required <?= $edit ? 'readonly' : '' ?>>
                            </div>
                            <?php if ($errors['code'] ?? false): ?>
                                <p class="help is-danger"><?= $errors['code'] ?></p>
                            <?php endif; ?>
                            <p class="help">This is the GC code of your new MOB Geocache.</p>
                        </div>
                        <div class="field">
                            <label class="label">MOB coordinates</label>
                            <div class="control">
                                <input type="text" class="input" v-mask="'N ##° ##.### E F##° ##.###'"
                                       name="coords" required
                                       v-model="mobCache.coords"
                                       placeholder="N ##° ##.### E ###° ##.###">
                            </div>
                            <?php if ($errors['coords'] ?? false): ?>
                                <p class="help is-danger"><?= $errors['coords'] ?></p>
                            <?php endif; ?>
                            <p class="help">Where the attendees should go to open the page? This should be probably the
                                same
                                as the coordinates of the geocache (mystery) icon.</p>
                        </div>
                        <div class="field">
                            <label class="label">Allowed radius</label>
                            <div class="control mb-6">
                                <vue-slider v-model="mobCache.radius" :min="10" :max="150" :interval="10"
                                            tooltip="always"
                                            :tooltip-formatter="(v) => `${v} m`"
                                            tooltip-placement="bottom"></vue-slider>
                                <input type="hidden" :value="mobCache.radius" name="radius">
                            </div>
                            <p class="help">How many meters from the above coordinates attendees can be to still count
                                them
                                for a MOB?</p>
                        </div>
                        <div class="field">
                            <label class="label">Required number of attendees</label>
                            <div class="control mb-6">
                                <vue-slider v-model="mobCache.minAttendees" :min="1" :max="20" tooltip="always"
                                            tooltip-placement="bottom"></vue-slider>
                                <input type="hidden" :value="mobCache.minAttendees" name="minAttendees">
                            </div>
                            <p class="help">How many devices should have the page opened to reveal the coordinates?</p>
                        </div>
                        <div class="field">
                            <label class="label">Final coordinates</label>
                            <div class="control">
                                <input type="text" class="input" v-mask="'N ##° ##.### E F##° ##.###'"
                                       v-model="mobCache.finalCoords" required
                                       name="finalCoords"
                                       placeholder="N ##° ##.### E ###° ##.###">
                            </div>
                            <?php if ($errors['finalCoords'] ?? false): ?>
                                <p class="help is-danger"><?= $errors['finalCoords'] ?></p>
                            <?php endif; ?>
                            <p class="help">
                                The coordinates of the final cache that will be revealed when the conditions are met.
                            </p>
                        </div>
                        <div class="field">
                            <label class="label">Final hint (optional)</label>
                            <div class="control">
                            <textarea class="textarea" name="finalHint"
                                      v-model="mobCache.finalHint" maxlength="250"
                                      placeholder="Look for the Suspicious Pile Of Rocks :-)"></textarea>
                            </div>
                            <p class="help">The message to show for all the attendees when the conditions are met.</p>
                        </div>

                        <div class="field is-grouped">
                            <div class="control">
                                <button type="submit" class="button is-link" id="submitbutton">
                                    <?= $edit ? 'Save changes' : 'Create!' ?>
                                </button>
                            </div>
                        </div>
                    </form>

                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
<footer class="has-text-centered mt-6 mb-0 p-0 pb-2 is-size-7">
    Made with ❤️ for geocaching and web by
    <a href="https://www.geocaching.com/p/?guid=49369c87-1a23-4cd6-a054-3c76cf2399f6">kranfagel</a>.
    <a href="https://www.geocaching.com/account/messagecenter?recipientId=49369c87-1a23-4cd6-a054-3c76cf2399f6&gcCode=GC8H8WF">
        Contact me
    </a> in case of questions or problems.
</footer>
<script src="https://cdn.jsdelivr.net/npm/vue@2.7.16/dist/vue.js"></script>
<script src="https://cdn.jsdelivr.net/npm/vue-slider-component@3.2.5/dist/vue-slider-component.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/v-mask/dist/v-mask.min.js"></script>
<script>

    Vue.use(VueMask.VueMaskPlugin, {
        placeholders: {
            N: /[NS]/,
            E: /[EW]/,
            F: /[01]/,
        }
    })

    new Vue({
        el: '#app',
        // directives: {
        //     mask: VueMask.VueMaskDirective,
        // },
        components: {
            VueSlider: window['vue-slider-component'],
            VueInputMask: window['vue-input-mask'],
        },
        data: function () {
            return {
                mobCache: {
                    code: <?=json_encode($mobCache['code'] ?? '') ?>,
                    coords: <?=json_encode($mobCache['coords'] ?? '')?>,
                    radius: <?=$mobCache['radius'] ?? 50 ?>,
                    minAttendees: <?=$mobCache['minAttendees'] ?? 5 ?>,
                    finalCoords: <?=json_encode($mobCache['finalCoords'] ?? '') ?>,
                    finalHint: <?=json_encode($mobCache['finalHint'] ?? '')?>,
                },
            };
        },
        mounted() {
            if (this.$refs.protectedForm) {
                this.waitForRecaptcha();
            }
        },
        methods: {
            waitForRecaptcha: function () {
                setTimeout(() => {
                    if (typeof window.grecaptcha !== 'undefined') {
                        window.grecaptcha.ready(() => this.renderRecaptcha());
                    } else {
                        this.waitForRecaptcha();
                    }
                }, 200);
            },
            renderRecaptcha: function () {
                this.widgetId = window.grecaptcha.render('submitbutton', {
                    sitekey: '6LcrSgQkAAAAANr_qLaie7eg5CVwQkKYDiYEKpmH',
                    size: 'invisible',
                    badge: 'bottomright',
                    theme: 'light',
                    callback: (token) => {
                        this.submitForm();
                        window.grecaptcha.reset(this.widgetId);
                    }
                });
                this.loaded = true;
            },
            submitForm() {
                this.$refs.protectedForm.submit();
            }
        }
    })
</script>
</body>
</html>
