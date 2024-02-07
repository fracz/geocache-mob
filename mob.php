<?php
require_once __DIR__ . '/recaptcha-verify.php';
$config = require __DIR__ . '/../../config/db.php';

$conn = new PDO("mysql:host=$config[DB_HOST];dbname=$config[DB_NAME];port=$config[DB_PORT];charset=utf8mb4", $config['DB_USER'], $config['DB_PASSWORD']);
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$mobCache = [];

$isPost = strtolower($_SERVER['REQUEST_METHOD']) === 'post';
$ip = $_SERVER["HTTP_CF_CONNECTING_IP"] ?? $_SERVER["REMOTE_ADDR"];

$code = '';
if (preg_match('#^GC[0-9A-Z]{4,5}$#', $_GET['code'])) {
    $code = $_GET['code'];
}

if ($code) {
    $sql = 'SELECT code, coords, radius, min_attendees minAttendees, delay_s delayS, final_coords finalCoords, final_hint finalHint FROM mob_cache WHERE code=?';
    $stmt = $conn->prepare($sql);
    $stmt->execute([$code]);
    $mobCache = $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($isPost) {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';
    $coords = $data['coords'] ?? '';
    $captcha = $data['captcha'] ?? '';
    $response = [];
    if ($action === 'reveal') {
        $sqlEligible = "SELECT COUNT(*) cnt FROM mob_attendee WHERE code=? AND last_online>DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ? SECOND) AND ip=?";
        $stmt = $conn->prepare($sqlEligible);
        $stmt->execute([$code, $mobCache['delayS'], $ip]);
        $eligible = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($eligible['cnt'] > 0) {
            $sqlCount = 'SELECT COUNT(*) cnt FROM mob_attendee WHERE code=? AND last_online>DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ? SECOND)';
            $stmt = $conn->prepare($sqlCount);
            $stmt->execute([$code, $mobCache['delayS']]);
            $count = $stmt->fetch(PDO::FETCH_ASSOC);
            $response['count'] = $count['cnt'];
            $response['missing'] = max(0, $mobCache['minAttendees'] - $count['cnt']);
            if ($response['missing'] === 0) {
                $response['finalCoords'] = $mobCache['finalCoords'];
                $response['finalHint'] = $mobCache['finalHint'];
            }
        }

    } elseif (isCaptchaValid($captcha)) {
        $from = coordsToDec($data['coords']);
        $to = coordsToDec($mobCache['coords']);
        if ($from && $to) {
            $distance = haversineGreatCircleDistance($from, $to);
            $response = ['distance' => $distance];
            if ($distance <= $mobCache['radius']) {
                $response['distanceOk'] = true;
                $response['delayS'] = $mobCache['delayS'];
                $sql = 'REPLACE INTO mob_attendee (code, ip, last_online, last_coords) VALUES(?,?,CURRENT_TIMESTAMP,?)';
                $stmt = $conn->prepare($sql);
                try {
                    $stmt->execute([$code, $ip, $data['coords']]);
                } catch (Exception $e) {
                    $response = ['error' => 'Application error.'];
                }
            }
        } else {
            $response = ['error' => 'Invalid coordinates.'];
        }
    } else {
        $response = ['error' => 'Invalid captcha.'];
    }
    echo json_encode($response);
    exit;
}

function haversineGreatCircleDistance($from, $to, $earthRadius = 6371000)
{
    [$latitudeFrom, $longitudeFrom] = $from;
    [$latitudeTo, $longitudeTo] = $to;
    $latFrom = deg2rad($latitudeFrom);
    $lonFrom = deg2rad($longitudeFrom);
    $latTo = deg2rad($latitudeTo);
    $lonTo = deg2rad($longitudeTo);
    $latDelta = $latTo - $latFrom;
    $lonDelta = $lonTo - $lonFrom;
    $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) + cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
    return $angle * $earthRadius;
}

function coordsToDec($latLng)
{
    if (preg_match('#^([NS]) (\d\d)° ([0-5]\d.\d\d\d)\'? ([EW]) ([01]\d\d)° ([0-5]\d.\d\d\d)\'?$#', $latLng, $match)) {
        $lat = intval($match[2]) + (floatval($match[3]) / 60) * ($match[1] === 'S' ? -1 : 1);
        $lng = intval($match[5]) + (floatval($match[6]) / 60) * ($match[4] === 'W' ? -1 : 1);
        return [$lat, $lng];
    } else {
        return null;
    }
}
?><!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $code ?> MOB Geocache</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@0.9.4/css/bulma.min.css">
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <style>
        .loadereye {
            position: relative;
            width: 108px;
            display: flex;
            justify-content: space-between;
        }

        .loadereye::after, .loadereye::before {
            content: '';
            display: inline-block;
            width: 48px;
            height: 48px;
            background-color: #FFF;
            background-image: radial-gradient(circle 14px, #0d161b 100%, transparent 0);
            background-repeat: no-repeat;
            border-radius: 50%;
            animation: eyeMove 10s infinite, blink 10s infinite;
        }

        @keyframes eyeMove {
            0%, 10% {
                background-position: 0px 0px
            }
            13%, 40% {
                background-position: -15px 0px
            }
            43%, 70% {
                background-position: 15px 0px
            }
            73%, 90% {
                background-position: 0px 15px
            }
            93%, 100% {
                background-position: 0px 0px
            }
        }

        @keyframes blink {
            0%, 10%, 12%, 20%, 22%, 40%, 42%, 60%, 62%, 70%, 72%, 90%, 92%, 98%, 100% {
                height: 48px
            }
            11%, 21%, 41%, 61%, 71%, 91%, 99% {
                height: 18px
            }
        }
    </style>
</head>
<body>
<section class="section" id="app">
    <div class="container">
        <?php if ($mobCache): ?>
            <h1 class="title">
                <?= $code ?> MOB Geocache
            </h1>
            <p class="subtitle">
                MOB Geocaches aim to gather certain number of people at specific place and ask them to open the same URL
                with their mobile devices.
                As soon as the system detects the required number of connected devices, it will display the final
                geocache coordinates and a hint, provided that the owner left one.
            </p>
            <p class="subtitle">
                In order for this to work you have to enable detection of your location for this website. Once you are
                ready,
                click the "I'm here" button in the same time as the others. Good luck!
            </p>
            <div class="has-text-centered">

                <h2 class="is-size-3">For the Geocache</h2>
                <p class="is-size-2">
                    <a href="https://coord.info/<?= $mobCache['code'] ?>" target="_blank"><?= $mobCache['code'] ?></a>
                </p>
                <h2 class="is-size-3">you must be at</h2>
                <p class="is-size-2"><?= $mobCache['coords'] ?></p>
                <p class="is-size-5">&plusmn; <?= $mobCache['radius'] ?> meters</p>
                <h2 class="is-size-3">and the MOB group must consist of</h2>
                <p class="is-size-2"><?= $mobCache['minAttendees'] ?> people</p>
                <p class="is-size-5">(at least)</p>
                <div v-if="!coords">
                    <h2 class="is-size-3">Are you ready?</h2>
                    <button type="button" class="button is-large is-primary mt-3" id="submitbutton">
                        I'm here!
                    </button>
                    <p class="help is-danger" v-if="disallowed">
                        You have to enable location discovery in order to take part in the MOB.
                    </p>
                </div>
                <div v-else>
                    <div v-if="!finalCoords">
                        <h2 class="is-size-3">Your location is</h2>
                        <p class="is-size-2">{{ formatCoords(coords) }}</p>
                        <div v-if="response">
                            <div v-if="response.error">
                                <p class="is-size-3 has-text-danger">{{ response.error }}</p>
                            </div>
                            <div v-else>
                                <p class="is-size-5">
                                    that is {{Math.round(response.distance)}} meters from the starting
                                    coordinates.
                                </p>
                                <div v-if="response.distanceOk">
                                    <p class="is-size-3 has-text-success">
                                        You are within the radius!
                                    </p>
                                    <p class="is-size-5">waiting for others...</p>
                                </div>
                                <p class="is-size-3 has-text-danger" v-else>You are too far away!</p>
                            </div>
                        </div>
                        <div class="is-flex is-align-items-center is-justify-content-center mt-3"
                             v-if="fetching || revealing">
                            <div class="is-flex is-align-items-center is-justify-content-center has-background-grey"
                                 style="height: 130px; width: 130px; border-radius: 50%">
                                <span class="loadereye"></span>
                            </div>
                        </div>
                        <div v-if="revealing" class="mt-5">
                            <p class="is-size-3 has-text-success" v-if="count <= 1">
                                You are the only one here.
                            </p>
                            <div v-else>
                                <p class="is-size-3 has-text-success">
                                    {{ count }} people here!
                                </p>
                                <p class="is-size-5" v-if="missing > 1">
                                    Waiting for {{ missing }} others...
                                </p>
                                <p class="is-size-5" v-else>
                                    Waiting for the last person!
                                </p>
                            </div>
                            <div class="mt-3">
                                <label>Time left:</label>
                                <progress class="progress is-info" :value="timeout" :max="timeoutMax"></progress>
                            </div>
                        </div>
                    </div>
                    <div v-else>
                        <article class="message is-success mt-5">
                            <div class="message-header"><p>Yay! You did it!</p></div>
                            <div class="message-body">
                                <h2 class="is-size-4">The final coordinates are:</h2>
                                <h2 class="is-size-2">{{ finalCoords }}</h2>
                                <div v-if="finalHint">
                                    <p class="is-size-3" style="white-space: pre-line">
                                        {{ finalHint }}
                                    </p>
                                </div>
                            </div>
                        </article>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <article class="message is-danger">
                <div class="message-header">
                    <p>MOB not found!</p>
                </div>
                <div class="message-body">
                    This MOB does not exist. Check the URL and try again. If you want to create a MOB for this cache,
                    go to the <a href="/mob/">homepage</a>.
                </div>
            </article>
        <?php endif; ?>
    </div>
</section>
<footer class="has-text-centered mt-6 mb-0 p-0 pb-2 is-size-7">
    Made with ❤️ for geocaching and web by
    <a href="https://www.geocaching.com/p/?guid=49369c87-1a23-4cd6-a054-3c76cf2399f6">kranfagel</a>.
    <a href="https://www.geocaching.com/account/messagecenter?recipientId=49369c87-1a23-4cd6-a054-3c76cf2399f6&gcCode=GC8H8WF">
        Contact me
    </a> in case of questions or problems.
    If you are the owner of this MOB, you can <a href="/mob/?edit=<?= $code ?>">edit it</a>.
</footer>
<script src="https://cdn.jsdelivr.net/npm/vue@2.7.16/dist/vue.js"></script>
<script>
    const zeroPad = (num, places) => String(num).padStart(places, '0');
    new Vue({
        el: '#app',
        data: function () {
            return {
                mobCache: {
                    code: <?=json_encode($mobCache['code'] ?? '') ?>,
                    coords: <?=json_encode($mobCache['coords'] ?? '')?>,
                    radius: <?=$mobCache['radius'] ?? 50 ?>,
                    minAttendees: <?=$mobCache['minAttendees'] ?? 5 ?>,
                },
                allowed: false,
                disallowed: false,
                coords: undefined,
                error: undefined,
                response: undefined,
                fetching: false,
                timeout: -1,
                timeoutMax: 0,
                revealing: false,
                finalCoords: '',
                finalHint: '',
                count: 0,
                missing: 0,
            };
        },
        mounted() {
            <?php if($mobCache): ?>
            this.waitForRecaptcha();
            <?php endif;?>
            setInterval(() => this.countdown(), 1000);
        },
        methods: {
            countdown() {
                if (this.timeout >= 0) {
                    if (this.timeout % 5 === 0) {
                        fetch('', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({action: 'reveal'})
                        }).then((response) => response.json())
                            .then(r => {
                                if (r.finalCoords) {
                                    this.revealing = false;
                                    this.finalCoords = r.finalCoords;
                                    this.finalHint = r.finalHint;
                                    this.timeout = -1;
                                } else if (this.timeout <= 0) {
                                    window.location.href = window.location;
                                } else {
                                    this.count = r.count;
                                    this.missing = r.missing;
                                }
                            });
                    }
                    this.timeout -= 1;
                }
            },
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
                    size: 'invisivble',
                    badge: 'bottomright',
                    theme: 'light',
                    callback: (token) => {
                        this.submitMob(token);
                        window.grecaptcha.reset(this.widgetId);
                    }
                });
                this.loaded = true;
            },
            submitMob(token) {
                navigator.geolocation
                    .getCurrentPosition((position) => this.success(position, token), () => this.disallowed = true);
            },
            success(position, captcha) {
                this.coords = position.coords;
                this.fetching = true;
                fetch('', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({coords: this.formatCoords(this.coords), captcha})
                }).then((response) => response.json())
                    .then(body => {
                        if (body.error) {
                            this.error = body.error;
                        } else {
                            this.response = body;
                            if (body.distanceOk) {
                                this.timeoutMax = body.delayS;
                                this.timeout = body.delayS;
                                this.revealing = true;
                            }
                        }
                    })
                    .finally(() => this.fetching = false);
            },
            formatCoord(coord, lat = true) {
                const dir = lat ? (coord.minus ? 'S' : 'N') : (coord.minus ? 'W' : 'E');
                return `${dir} ${zeroPad(coord.deg, lat ? 2 : 3)}° ${zeroPad(coord.min, 2)}.${zeroPad(Math.round(coord.minDec), 3)}'`;
            },
            coordsDecToDdm(coord) {
                const minus = coord < 0;
                coord = Math.abs(coord);
                return {
                    minus,
                    deg: Math.floor(coord),
                    min: Math.abs(Math.floor((coord % 1) * 60)),
                    minDec: Math.abs(Math.round((((coord % 1) * 60) % 1) * 1000)),
                };
            },
            formatCoords(coords) {
                const lat = this.coordsDecToDdm(coords.latitude);
                const lng = this.coordsDecToDdm(coords.longitude);
                return `${this.formatCoord(lat)} ${this.formatCoord(lng, false)}`;
            }
        }
    })
</script>
</body>
</html>
