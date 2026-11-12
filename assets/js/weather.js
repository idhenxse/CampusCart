document.addEventListener('DOMContentLoaded', () => {

    // =========================================
    // METRO MANILA WEATHER - OPEN-METEO API
    // =========================================

    const latitude = 14.5995;
    const longitude = 120.9842;

    const weatherUrl =
        'https://api.open-meteo.com/v1/forecast' +
        '?latitude=' + latitude +
        '&longitude=' + longitude +
        '&current=temperature_2m' +
        ',relative_humidity_2m' +
        ',apparent_temperature' +
        ',weather_code' +
        ',wind_speed_10m' +
        ',is_day' +
        '&temperature_unit=celsius' +
        '&wind_speed_unit=kmh' +
        '&timezone=Asia%2FManila';

    // Weather widget elements
    const weatherIcon = document.getElementById('weather-icon');
    const temperature = document.getElementById('weather-temperature');
    const description = document.getElementById('weather-description');
    const feelsLike = document.getElementById('weather-feels-like');
    const humidity = document.getElementById('weather-humidity');
    const wind = document.getElementById('weather-wind');
    const status = document.getElementById('weather-status');

    // Stop if the widget is not present.
    if (
        !weatherIcon ||
        !temperature ||
        !description ||
        !feelsLike ||
        !humidity ||
        !wind ||
        !status
    ) {
        return;
    }

    // =========================================
    // WEATHER CODE DESCRIPTIONS AND ICONS
    // WMO WEATHER INTERPRETATION CODES
    // =========================================

    function getWeatherInfo(code, isDay) {

        const weatherConditions = {

            0: {
                description: 'Clear sky',
                dayIcon: '☀️',
                nightIcon: '🌙'
            },

            1: {
                description: 'Mainly clear',
                dayIcon: '🌤️',
                nightIcon: '🌙'
            },

            2: {
                description: 'Partly cloudy',
                dayIcon: '⛅',
                nightIcon: '☁️'
            },

            3: {
                description: 'Overcast',
                dayIcon: '☁️',
                nightIcon: '☁️'
            },

            45: {
                description: 'Foggy',
                dayIcon: '🌫️',
                nightIcon: '🌫️'
            },

            48: {
                description: 'Depositing rime fog',
                dayIcon: '🌫️',
                nightIcon: '🌫️'
            },

            51: {
                description: 'Light drizzle',
                dayIcon: '🌦️',
                nightIcon: '🌧️'
            },

            53: {
                description: 'Moderate drizzle',
                dayIcon: '🌦️',
                nightIcon: '🌧️'
            },

            55: {
                description: 'Dense drizzle',
                dayIcon: '🌧️',
                nightIcon: '🌧️'
            },

            56: {
                description: 'Light freezing drizzle',
                dayIcon: '🌧️',
                nightIcon: '🌧️'
            },

            57: {
                description: 'Dense freezing drizzle',
                dayIcon: '🌧️',
                nightIcon: '🌧️'
            },

            61: {
                description: 'Slight rain',
                dayIcon: '🌦️',
                nightIcon: '🌧️'
            },

            63: {
                description: 'Moderate rain',
                dayIcon: '🌧️',
                nightIcon: '🌧️'
            },

            65: {
                description: 'Heavy rain',
                dayIcon: '🌧️',
                nightIcon: '🌧️'
            },

            66: {
                description: 'Light freezing rain',
                dayIcon: '🌧️',
                nightIcon: '🌧️'
            },

            67: {
                description: 'Heavy freezing rain',
                dayIcon: '🌧️',
                nightIcon: '🌧️'
            },

            71: {
                description: 'Slight snowfall',
                dayIcon: '🌨️',
                nightIcon: '🌨️'
            },

            73: {
                description: 'Moderate snowfall',
                dayIcon: '🌨️',
                nightIcon: '🌨️'
            },

            75: {
                description: 'Heavy snowfall',
                dayIcon: '❄️',
                nightIcon: '❄️'
            },

            77: {
                description: 'Snow grains',
                dayIcon: '❄️',
                nightIcon: '❄️'
            },

            80: {
                description: 'Slight rain showers',
                dayIcon: '🌦️',
                nightIcon: '🌧️'
            },

            81: {
                description: 'Moderate rain showers',
                dayIcon: '🌧️',
                nightIcon: '🌧️'
            },

            82: {
                description: 'Violent rain showers',
                dayIcon: '⛈️',
                nightIcon: '⛈️'
            },

            85: {
                description: 'Slight snow showers',
                dayIcon: '🌨️',
                nightIcon: '🌨️'
            },

            86: {
                description: 'Heavy snow showers',
                dayIcon: '❄️',
                nightIcon: '❄️'
            },

            95: {
                description: 'Thunderstorm',
                dayIcon: '⛈️',
                nightIcon: '⛈️'
            },

            96: {
                description: 'Thunderstorm with slight hail',
                dayIcon: '⛈️',
                nightIcon: '⛈️'
            },

            99: {
                description: 'Thunderstorm with heavy hail',
                dayIcon: '⛈️',
                nightIcon: '⛈️'
            }

        };

        const condition = weatherConditions[code];

        if (!condition) {
            return {
                description: 'Weather condition unavailable',
                icon: '🌡️'
            };
        }

        return {
            description: condition.description,
            icon: Number(isDay) === 1
                ? condition.dayIcon
                : condition.nightIcon
        };
    }

    // =========================================
    // DISPLAY LOADING STATE
    // =========================================

    function showLoading() {

        weatherIcon.textContent = '⏳';
        temperature.textContent = '--°C';
        description.textContent = 'Loading weather...';

        feelsLike.textContent = '--°C';
        humidity.textContent = '--%';
        wind.textContent = '-- km/h';

        status.textContent = 'Getting the latest weather...';

    }

    // =========================================
    // DISPLAY ERROR STATE
    // =========================================

    function showError() {

        weatherIcon.textContent = '⚠️';
        temperature.textContent = '--°C';
        description.textContent = 'Weather unavailable';

        feelsLike.textContent = '--°C';
        humidity.textContent = '--%';
        wind.textContent = '-- km/h';

        status.textContent =
            'Unable to load weather. Please try again later.';

    }

    // =========================================
    // FETCH LIVE WEATHER DATA
    // =========================================

    async function loadWeather() {

        showLoading();

        try {

            const response = await fetch(weatherUrl, {
                method: 'GET',
                cache: 'no-store'
            });

            if (!response.ok) {
                throw new Error('Weather API request failed.');
            }

            const data = await response.json();

            if (
                !data.current ||
                data.current.temperature_2m === undefined ||
                data.current.relative_humidity_2m === undefined ||
                data.current.apparent_temperature === undefined ||
                data.current.weather_code === undefined ||
                data.current.wind_speed_10m === undefined
            ) {
                throw new Error('Incomplete weather data.');
            }

            const current = data.current;

            const values = [
                current.temperature_2m,
                current.relative_humidity_2m,
                current.apparent_temperature,
                current.weather_code,
                current.wind_speed_10m,
                current.is_day
            ];

            if (!values.every(value =>
                value !== null && value !== undefined && Number.isFinite(Number(value))
            )) {
                throw new Error('Invalid weather values.');
            }

            // Get the matching weather description and icon.
            const weatherInfo = getWeatherInfo(
                Number(current.weather_code),
                Number(current.is_day)
            );

            // Display actual API values.
            weatherIcon.textContent = weatherInfo.icon;

            temperature.textContent =
                `${Math.round(Number(current.temperature_2m))}°C`;

            description.textContent =
                weatherInfo.description;

            feelsLike.textContent =
                `${Math.round(Number(current.apparent_temperature))}°C`;

            humidity.textContent =
                `${Math.round(Number(current.relative_humidity_2m))}%`;

            wind.textContent =
                `${Number(current.wind_speed_10m).toFixed(1)} km/h`;

            // Show the API's latest observation time.
            status.textContent =
                `Updated ${current.time.replace('T', ' ')} (Manila time)`;

        } catch (error) {

            console.error('CampusCart weather error:', error);

            showError();

        }

    }

    // =========================================
    // INITIAL WEATHER LOAD
    // =========================================

    loadWeather();

    // Refresh weather every 15 minutes.
    setInterval(loadWeather, 15 * 60 * 1000);

});
