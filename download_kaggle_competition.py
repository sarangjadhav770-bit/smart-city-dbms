"""Download the Mumbai Nagar Seva BMC complaint-resolution competition data."""

import kagglehub


COMPETITION = "mumbai-nagar-seva-bmc-civic-complaint-resolution-2018-2024"


def main() -> None:
    path = kagglehub.competition_download(COMPETITION)
    print(f"Path to competition files: {path}")


if __name__ == "__main__":
    main()
